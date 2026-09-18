/* =========================================================================
 * owlsgo · 前端交互
 * -------------------------------------------------------------------------
 * 设计原则：
 *   1. 渐进增强 —— 所有功能在没有 JS 时仍然可用（表单正常提交、链接正常跳转）
 *   2. 无内联脚本 —— 站点的 CSP 只允许 'self'，因此不使用 onclick 之类的内联属性，
 *      统一在 <body> 上做事件委托
 *   3. 零依赖 —— 只用浏览器原生 API，不引入任何第三方库
 * ========================================================================= */

(function () {
  'use strict';

  /** 读取 <meta name="csrf-token"> 中的令牌 */
  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') || '' : '';
  }

  /** 统一提示：优先使用 OATUI 的 toast，缺失时退化为原生 alert */
  /*
   * 弹出提示的停留时长（毫秒）。
   *
   * OATUI 的默认值是 4000ms，偏长：连着几条操作提示会叠在一起挡住内容。
   * 这里统一压缩 —— 成功、提示类扫一眼就够；出错、警告类需要读完，留得略久一点。
   * 悬停时 OATUI 会自动暂停倒计时，所以停留时间短也不会来不及看。
   */
  var TOAST_DURATION = {
    success: 2000,
    info: 2000,
    warning: 3200,
    danger: 3200,
    error: 3200
  };

  function notify(message, variant) {
    if (!message) {
      return;
    }

    var type = variant || 'info';

    if (window.ot && typeof window.ot.toast === 'function') {
      var titles = { success: '成功', danger: '出错了', error: '出错了', warning: '请注意', info: '提示' };
      window.ot.toast(message, titles[type] || '提示', {
        variant: type,
        duration: TOAST_DURATION[type] || 2000
      });
      return;
    }

    window.alert(message);
  }

  /* =======================================================================
   * 1. 一次性提示消息 → toast
   * ===================================================================== */

  function initFlash() {
    var flash = document.querySelector('[data-flash]');
    if (!flash) {
      return;
    }

    var message = flash.getAttribute('data-message') || '';
    var type = flash.getAttribute('data-type') || 'info';

    // 载体只负责把服务端的一次性提示递给前端，读取后立即移除
    if (flash.parentNode) {
      flash.parentNode.removeChild(flash);
    }

    if (!message) {
      return;
    }

    // 与 AJAX 操作共用同一个 notify()，保证「用户操作反馈」只有弹出式通知这一种样式
    var variant = 'info';
    if (type === 'success') {
      variant = 'success';
    } else if (type === 'error' || type === 'danger') {
      variant = 'danger';
    } else if (type === 'warning') {
      variant = 'warning';
    }

    // 延迟一拍，确保 oat.js 的 toast 容器已初始化
    window.setTimeout(function () {
      notify(message, variant);
    }, 80);
  }

  /* =======================================================================
   * 2. 危险操作二次确认（form[data-confirm]）
   * ===================================================================== */

  /*
   * 自定义确认对话框（替代 window.confirm）
   *
   * 返回 Promise<boolean>；布局里需存在 <dialog id="app-confirm">。
   * 对话框缺失（或浏览器不支持 showModal）时退回原生 confirm，保证功能可用。
   */
  function uiConfirm(message) {
    return new Promise(function (resolve) {
      var dialog = document.getElementById('app-confirm');
      if (!dialog || typeof dialog.showModal !== 'function') {
        resolve(window.confirm(message));
        return;
      }

      dialog.querySelector('[data-confirm-message]').textContent = message;

      var settled = false;
      function done(value) {
        if (settled) {
          return;
        }
        settled = true;
        dialog.removeEventListener('close', onClose);
        resolve(value);
      }
      function onClose() {
        // 按 Esc 或点遮罩关闭都视为取消
        done(dialog.returnValue === 'ok');
      }

      dialog.addEventListener('close', onClose);
      dialog.querySelector('[data-confirm-ok]').onclick = function () {
        dialog.returnValue = 'ok';
        dialog.close('ok');
      };
      dialog.querySelector('[data-confirm-cancel]').onclick = function () {
        dialog.returnValue = 'cancel';
        dialog.close('cancel');
      };

      dialog.showModal();
    });
  }

  function initConfirm() {
    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!form || !form.matches || !form.matches('form[data-confirm]')) {
        return;
      }

      var message = form.getAttribute('data-confirm') || '确定要执行该操作吗？';

      /*
       * 必须同时阻断默认提交与后续监听器。
       * 危险表单往往同时挂 data-confirm 和 data-ajax：这里的 preventDefault()
       * 只能拦住页面导航，拦不住后面注册的 AJAX 监听器 —— 曾经因此出现
       * 「点了取消，帖子还是被删掉」的严重 bug。stopImmediatePropagation()
       * 会拦住同一元素上后注册的全部监听器，确认通过后再手动重新提交。
       */
      event.preventDefault();
      event.stopImmediatePropagation();

      uiConfirm(message).then(function (ok) {
        if (!ok) {
          return;
        }
        // 二次提交不再询问；交还给普通提交 / AJAX 监听器按各自流程走
        form.removeAttribute('data-confirm');
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.submit();
        }
      });
    });
  }

  /* =======================================================================
   * 2.1 游客守卫（form[data-require-login] / a[data-require-login]）
   *
   * 搜索框与移动端搜索入口对游客也显示，但提交/点击时就地提示「请登录后操作」，
   * 不打断浏览（把提示放在前端，避免游客被直接弹到登录页）。
   * 渐进增强：没有 JS 时表单照常提交，由服务端 requireLogin() 兜底跳登录页。
   * ===================================================================== */

  function initGuestGuard() {
    var message = '请登录后操作';

    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!form || !form.matches || !form.matches('form[data-require-login]')) {
        return;
      }

      event.preventDefault();
      notify(message, 'warning');
    });

    document.addEventListener('click', function (event) {
      if (!event.target || !event.target.closest) {
        return;
      }

      var link = event.target.closest('a[data-require-login]');
      if (!link) {
        return;
      }

      event.preventDefault();
      notify(message, 'warning');
    });
  }

  /* =======================================================================
   * 3. AJAX 表单（点赞 / 收藏 / 标记已读 等）
   * ===================================================================== */

  function applyState(form, json) {
    var stateField = form.getAttribute('data-state-field') || '';

    if (stateField && typeof json[stateField] !== 'undefined') {
      var active = !!json[stateField];

      form.setAttribute('data-active', active ? '1' : '0');

      var trigger = form.querySelector('[data-state-target]') || form.querySelector('button');
      if (trigger) {
        trigger.setAttribute('aria-pressed', active ? 'true' : 'false');
        trigger.setAttribute('data-variant', active ? 'primary' : 'secondary');
      }
    }

    if (typeof json.count !== 'undefined') {
      var counter = form.querySelector('[data-count]');
      if (counter) {
        counter.textContent = json.count;
      }
    }

    if (typeof json.unread !== 'undefined') {
      var dots = document.querySelectorAll('[data-unread-badge]');
      for (var i = 0; i < dots.length; i++) {
        if (json.unread > 0) {
          dots[i].textContent = json.unread;
          dots[i].removeAttribute('hidden');
        } else {
          dots[i].setAttribute('hidden', 'hidden');
        }
      }
    }
  }

  /* =======================================================================
   * 安装向导：数据库类型联动 + 提交前勾选确认
   * ===================================================================== */

  /* =======================================================================
   * 登录验证码：点击图片换一张
   * ===================================================================== */

  function initCaptcha() {
    var img = document.querySelector('[data-captcha-img]');

    if (!img) {
      return;
    }

    img.addEventListener('click', function () {
      var base = img.getAttribute('data-src') || img.getAttribute('src') || '';

      if (!base) {
        return;
      }

      // 服务端已经发了 no-store，这里再补一个时间戳，
      // 防止个别浏览器/代理仍按旧地址返回缓存里的那张图
      img.setAttribute('src', base + (base.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now());
    });
  }

  function initInstallForm() {
    var form = document.querySelector('form[data-install-form]');

    if (!form) {
      return;
    }

    var driver    = form.querySelector('[data-install-driver]');
    var remoteBox = form.querySelector('[data-install-remote]');
    var submit    = form.querySelector('[data-install-submit]');
    var checks    = form.querySelectorAll('[data-install-agree]');

    // 环境不达标时后端已判定不可安装，前端不能擅自把按钮解禁
    var blocked = !!(submit && submit.hasAttribute('data-install-blocked'));

    function syncDriver() {
      if (!driver) {
        return;
      }

      // SQLite 不需要任何连接信息，选它时整块远程字段收起来
      if (remoteBox) {
        remoteBox.hidden = driver.value === 'sqlite';
      }
    }

    function syncSubmit() {
      if (!submit) {
        return;
      }

      if (blocked) {
        submit.disabled = true;
        return;
      }

      var ready = true;

      for (var i = 0; i < checks.length; i++) {
        if (!checks[i].checked) {
          ready = false;
          break;
        }
      }

      submit.disabled = !ready;
    }

    if (driver) {
      driver.addEventListener('change', syncDriver);
    }

    for (var j = 0; j < checks.length; j++) {
      checks[j].addEventListener('change', syncSubmit);
    }

    syncDriver();
    syncSubmit();
  }

  /* =======================================================================
   * 2.2 头像：裁切对话框 + 预置头像库
   *
   * 上传流程：选文件 → 弹窗内缩放（cover 基准 × 1~3）→ 「上传并应用」
   * 把 canvas 导出的 PNG 塞回 file input，走原有的 POST /settings/avatar，
   * 服务端校验与存储逻辑完全不变。
   * ===================================================================== */

  function initAvatar() {
    var fileInput = document.getElementById('avatar-file');
    var pickBtn = document.getElementById('avatar-pick');
    var presetBtn = document.getElementById('avatar-preset-open');
    if (!fileInput || !pickBtn) {
      return;   // 不在账号设置页
    }

    var form = fileInput.closest('form');

    pickBtn.addEventListener('click', function () {
      fileInput.click();
    });

    // ---- 裁切对话框 ----
    var dialog = document.getElementById('avatar-crop');
    var canvas = document.getElementById('avatar-crop-canvas');
    var zoom = document.getElementById('avatar-crop-zoom');
    var ctx = canvas ? canvas.getContext('2d') : null;
    var img = null;
    var scale = 1;
    var SIZE = canvas ? canvas.width : 280;

    function draw() {
      if (!img || !ctx) {
        return;
      }

      ctx.clearRect(0, 0, SIZE, SIZE);
      ctx.fillStyle = '#f2f5f9';
      ctx.fillRect(0, 0, SIZE, SIZE);

      // cover 基准：铺满画布所需的最小缩放；滑条在此基础上 1~3 倍放大，居中裁切。
      // 滑条值直接从这里读——之前滑条只调 draw() 却没人更新 scale，导致缩放无效。
      var base = Math.max(SIZE / img.width, SIZE / img.height);
      var z = zoom ? parseFloat(zoom.value) : scale;
      if (!isFinite(z) || z < 1) {
        z = 1;
      }
      var s = base * z;
      var w = img.width * s;
      var h = img.height * s;

      ctx.drawImage(img, (SIZE - w) / 2, (SIZE - h) / 2, w, h);
    }

    function openCrop(file) {
      if (!dialog || !canvas || typeof dialog.showModal !== 'function') {
        // 对话框不可用时退回原流程：不裁切直接提交
        if (form) {
          form.submit();
        }
        return;
      }

      var url = URL.createObjectURL(file);
      img = new Image();
      img.onload = function () {
        URL.revokeObjectURL(url);
        scale = 1;
        if (zoom) {
          zoom.value = '1';
        }
        draw();
        dialog.showModal();
      };
      img.onerror = function () {
        URL.revokeObjectURL(url);
        notify('图片读取失败，请换一张试试。', 'danger');
      };
      img.src = url;
    }

    fileInput.addEventListener('change', function () {
      if (fileInput.files && fileInput.files[0]) {
        openCrop(fileInput.files[0]);
      }
    });

    if (zoom) {
      zoom.addEventListener('input', draw);
    }

    var cropCancel = dialog ? dialog.querySelector('[data-crop-cancel]') : null;
    var cropOk = dialog ? dialog.querySelector('[data-crop-ok]') : null;

    if (cropCancel) {
      cropCancel.addEventListener('click', function () {
        dialog.close();
        fileInput.value = '';
      });
    }

    if (cropOk) {
      cropOk.addEventListener('click', function () {
        draw();
        canvas.toBlob(function (blob) {
          if (!blob) {
            notify('头像生成失败，请重试。', 'danger');
            return;
          }

          // 把裁切结果塞回 file input，再走原有的 POST /settings/avatar
          var dt = new DataTransfer();
          dt.items.add(new File([blob], 'avatar.png', { type: 'image/png' }));
          fileInput.files = dt.files;

          if (form) {
            form.submit();
          }
        }, 'image/png');
      });
    }

    // ---- 预置头像库 ----
    var presetDialog = document.getElementById('avatar-preset-dialog');
    var presetForm = document.getElementById('avatar-preset-form');

    if (presetBtn && presetDialog) {
      presetBtn.addEventListener('click', function () {
        if (typeof presetDialog.showModal === 'function') {
          presetDialog.showModal();
        }
      });
    }

    if (presetDialog) {
      var cancel = presetDialog.querySelector('[data-preset-cancel]');
      if (cancel) {
        cancel.addEventListener('click', function () {
          presetDialog.close();
        });
      }

      Array.prototype.forEach.call(presetDialog.querySelectorAll('.avatar-preset'), function (btn) {
        btn.addEventListener('click', function () {
          if (!presetForm) {
            return;
          }
          presetForm.querySelector('[name="seed"]').value = btn.getAttribute('data-preset-seed') || '';
          presetForm.querySelector('[name="style"]').value = btn.getAttribute('data-preset-style') || '';
          presetForm.submit();
        });
      });
    }
  }

  /* ------------------------------------------------------------------ */
  /*  草稿自动保存（发帖 / 回帖）：刷新、离开或误关页面都不丢内容        */
  /* ------------------------------------------------------------------ */

  var DRAFT_PREFIX = 'owlsgo:draft:';
  var draftTimers  = {};

  /** localStorage 在隐私模式下可能不可用，取不到就整体降级为「不保存」 */
  function draftStorage() {
    try {
      return window.localStorage;
    } catch (e) {
      return null;
    }
  }

  function draftKey(form) {
    return DRAFT_PREFIX + form.getAttribute('data-draft');
  }

  function draftTime(ts) {
    var d = new Date(ts);
    var p = function (n) { return n < 10 ? '0' + n : String(n); };
    return p(d.getHours()) + ':' + p(d.getMinutes());
  }

  /** 采集当前内容：标题 + 正文 + 已上传的附件（附件的原始字段存在行上） */
  function collectDraft(form) {
    var data = { title: '', content: '', attachments: [], saved_at: Date.now() };

    var title = form.querySelector('input[name="title"]');
    if (title) {
      data.title = title.value;
    }

    var content = form.querySelector('textarea[name="content"]');
    if (content) {
      data.content = content.value;
    }

    var box = form.querySelector('.attachment-list');
    if (box) {
      Array.prototype.forEach.call(box.querySelectorAll('.attachment-row[data-attachment]'), function (row) {
        data.attachments.push({
          id:        row.getAttribute('data-attachment'),
          name:      row.getAttribute('data-att-name') || '',
          size_text: row.getAttribute('data-att-size') || '',
          is_image:  row.getAttribute('data-att-image') === '1',
          url:       row.getAttribute('data-att-url') || ''
        });
      });
    }

    return data;
  }

  function draftIsEmpty(data) {
    return !data || (!data.title && !data.content && data.attachments.length === 0);
  }

  /**
   * 草稿与当前表单是否不同。
   *
   * 编辑页的表单是由服务端内容预填的：如果草稿跟页面上的内容一模一样
   * （比如刚恢复过又刷新了一次），就没有必要再弹「恢复 / 丢弃」。
   */
  function draftDiffers(form, data) {
    if (draftIsEmpty(data)) {
      return false;
    }

    var title = form.querySelector('input[name="title"]');
    if (title && (data.title || '') !== title.value) {
      return true;
    }

    var content = form.querySelector('textarea[name="content"]');
    if (content && (data.content || '') !== content.value) {
      return true;
    }

    var current = [];
    var box = form.querySelector('.attachment-list');
    if (box) {
      Array.prototype.forEach.call(box.querySelectorAll('.attachment-row[data-attachment]'), function (row) {
        current.push(String(row.getAttribute('data-attachment')));
      });
    }

    var saved = (data.attachments || []).map(function (att) {
      return String(att.id);
    });

    return current.join(',') !== saved.join(',');
  }

  function saveDraft(form) {
    var store = draftStorage();
    if (!store) {
      return;
    }

    /*
     * 关键：草稿条还在等用户「恢复 / 丢弃」时，表单其实是空的。
     * 这时若触发自动保存（刷新会走 pagehide），会把待恢复的草稿覆盖成空内容
     * ——表现为「再刷新一次草稿就没了」。所以未决之前一律不写。
     */
    if (form.__draftPending && !form.__draftResolved) {
      return;
    }

    /*
     * 已提交：内容已经交给服务端了。提交必然伴随页面卸载（跳转），
     * 卸载时的补存若照常执行，就会把刚提交的内容又写回草稿
     * —— 「点了提交，回来却又弹出草稿」就是这么来的。
     */
    if (form.__draftSubmitted) {
      return;
    }

    var data = collectDraft(form);
    if (draftIsEmpty(data)) {
      store.removeItem(draftKey(form));
      return;
    }

    try {
      store.setItem(draftKey(form), JSON.stringify(data));
    } catch (e) {
      return;                          // 配额满等异常不打扰用户
    }
    /*
     * 自动保存是静默的：不弹任何提示条。
     * 提示条只在「回到页面、有草稿可恢复」时出现（见 initDrafts），
     * 避免常驻浮层干扰界面。
     */
  }

  function readDraft(form) {
    var store = draftStorage();
    if (!store) {
      return null;
    }

    try {
      var raw = store.getItem(draftKey(form));
      return raw ? JSON.parse(raw) : null;
    } catch (e) {
      return null;
    }
  }

  function clearDraft(form) {
    var store = draftStorage();
    if (store) {
      store.removeItem(draftKey(form));
    }
  }

  function scheduleDraft(form) {
    var key = draftKey(form);
    if (draftTimers[key]) {
      window.clearTimeout(draftTimers[key]);
    }
    draftTimers[key] = window.setTimeout(function () {
      saveDraft(form);
    }, 600);
  }

  /**
   * 草稿提示卡：只在「回到页面、有草稿可恢复」时出现，
   * 固定在右下角（不占据文档流、不推动页面内容），
   * 按钮为「丢弃草稿」在前、「恢复草稿」在后（丢弃在前）。
   */
  function buildDraftNotice(form) {
    var wrap = document.createElement('div');
    wrap.className = 'draft-notice';

    var text = document.createElement('span');
    text.className = 'draft-notice__text';
    wrap.appendChild(text);

    var actions = document.createElement('div');
    actions.className = 'draft-notice__actions';

    var discard = document.createElement('button');
    discard.type = 'button';
    discard.className = 'button outline small';
    discard.textContent = '丢弃草稿';
      discard.addEventListener('click', function () {
        clearDraft(form);
        form.__draftResolved = true;
        if (wrap.parentNode) {
          wrap.parentNode.removeChild(wrap);
        }
        form.__draftNotice = null;
        notify('草稿已丢弃。', 'info');
      });

      var restore = document.createElement('button');
      restore.type = 'button';
      restore.className = 'button small';
      restore.textContent = '恢复草稿';
      restore.addEventListener('click', function () {
        var data = readDraft(form);
        if (draftIsEmpty(data)) {
          return;
        }

        // 标题 / 正文：草稿比页面上（可能是服务端原内容）更新，直接覆盖
        var title = form.querySelector('input[name="title"]');
        if (title) {
          title.value = data.title || '';
        }

        var content = form.querySelector('textarea[name="content"]');
        if (content) {
          content.value = data.content || '';
          content.dispatchEvent(new Event('input', { bubbles: true }));
        }

        // 附件行按保存时的字段重建（先清掉现有行，保证与草稿完全一致），不重新上传
        var box = form.querySelector('.attachment-list');
        if (box) {
          Array.prototype.forEach.call(
            box.querySelectorAll('.attachment-row[data-attachment]'),
            function (row) {
              if (row.parentNode) {
                row.parentNode.removeChild(row);
              }
            }
          );

          if (data.attachments.length > 0) {
            data.attachments.forEach(function (att) {
              renderAttachment(box, att);
            });
          }
          syncInsertButtons();
        }

        form.__draftResolved = true;
        if (wrap.parentNode) {
          wrap.parentNode.removeChild(wrap);
        }
        form.__draftNotice = null;
        notify('草稿已恢复。', 'success');
        saveDraft(form);               // 恢复后的内容立刻再存一次，避免刚恢复就丢失
      });

    actions.appendChild(discard);
    actions.appendChild(restore);
    wrap.appendChild(actions);

    return wrap;
  }

  function initDrafts() {
    Array.prototype.forEach.call(document.querySelectorAll('form[data-draft]'), function (form) {
      var draft = readDraft(form);

      if (draftDiffers(form, draft)) {
        var notice = buildDraftNotice(form);
        notice.querySelector('.draft-notice__text').textContent =
          '检测到未提交的草稿（保存于 ' + draftTime(draft.saved_at) + '）';
        // 挂到 body 上做成右下角浮层，不插入表单（避免把页面内容顶下去）
        document.body.appendChild(notice);
        form.__draftNotice   = notice;
        form.__draftPending  = true;
        form.__draftResolved = false;
      }

      /**
       * 用户重新开始编辑（输入文字 / 点附件按钮）：
       *  - 撤掉「待恢复草稿」提示，视为已处理；
       *  - 解除「已提交」状态 —— 提交被打回（422）后继续修改，自动保存要能恢复。
       */
      function resumeEditing() {
        form.__draftSubmitted = false;

        if (form.__draftPending && !form.__draftResolved) {
          form.__draftResolved = true;
          if (form.__draftNotice && form.__draftNotice.parentNode) {
            form.__draftNotice.parentNode.removeChild(form.__draftNotice);
          }
          form.__draftNotice = null;
        }
      }

      // 输入即存（防抖 600ms）
      form.addEventListener('input', function (event) {
        if (event.target && event.target.matches
          && event.target.matches('input[name="title"], textarea[name="content"]')) {
          resumeEditing();
          scheduleDraft(form);
        }
      });

      // 附件的增删走事件委托，点击后补存一次
      form.addEventListener('click', function (event) {
        if (event.target && event.target.closest && event.target.closest('.attachment-icon-btn')) {
          resumeEditing();
          scheduleDraft(form);
        }
      });

      // 关闭 / 切走页面前补存一次（防抖可能还没落盘）
      window.addEventListener('pagehide', function () {
        saveDraft(form);
      });

      document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
          saveDraft(form);
        }
      });

      /*
       * 提交：草稿立刻作废，并置 __draftSubmitted 让随后的补存（pagehide /
       * visibilitychange / 尚未触发的防抖定时器）全部跳过。
       * 若提交被服务端打回（校验失败，页面留在原地），用户再次输入会复位该标志，
       * 自动保存照常恢复（见 resumeEditing）。
       */
      form.addEventListener('submit', function () {
        form.__draftSubmitted = true;
        form.__draftPending   = false;
        clearDraft(form);

        if (form.__draftNotice && form.__draftNotice.parentNode) {
          form.__draftNotice.parentNode.removeChild(form.__draftNotice);
        }
        form.__draftNotice = null;
      });
    });
  }

  /* ------------------------------------------------------------------ */
  /*  隐私开关：切换时同步旁边的状态文字（所有人可见 / 仅自己可见）      */
  /* ------------------------------------------------------------------ */

  function initSwitchStates() {
    Array.prototype.forEach.call(document.querySelectorAll('.switch[data-state-text]'), function (input) {
      var row   = input.closest ? input.closest('.privacy-row') : null;
      var state = row ? row.querySelector('.privacy-row__state') : null;

      if (!state) {
        return;
      }

      var sync = function () {
        state.textContent = input.checked ? '所有人可见' : '仅自己可见';
      };

      input.addEventListener('change', sync);
      sync();
    });
  }

  function initAjaxForms() {
    document.addEventListener('submit', function (event) {
      var form = event.target;

      if (!form || !form.matches || !form.matches('form[data-ajax]')) {
        return;
      }

      event.preventDefault();

      var submitter = event.submitter || form.querySelector('button[type="submit"], button:not([type])');

      if (submitter && submitter.disabled) {
        return;
      }

      if (submitter) {
        submitter.disabled = true;
      }

      // 必须把 submitter 传给 FormData，否则「测试数据库连接」这种带 name/value
      // 的提交按钮不会被包含进去；同时也不能用 form.action，因为一旦表单里有
      // 控件 name="action"，form.action 会返回那个 DOM 元素而不是 action URL。
      var body;
      try {
        body = new FormData(form, submitter);
      } catch (e) {
        body = new FormData(form);
        if (submitter && submitter.name) {
          body.append(submitter.name, submitter.value);
        }
      }
      if (csrfToken() && !body.has('_token')) {
        body.append('_token', csrfToken());
      }

      fetch(form.getAttribute('action') || window.location.href, {
        method: (form.getAttribute('method') || 'POST').toUpperCase(),
        body: body,
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'X-OWLSGO-Response': 'json'
        }
      })
        .then(function (response) {
          return response.json().catch(function () {
            return { ok: false, message: '服务器返回了无法解析的内容。' };
          }).then(function (json) {
            return { ok: response.ok, status: response.status, json: json };
          });
        })
        .then(function (result) {
          var json = result.json || {};

          if (result.ok && json.ok !== false) {
            applyState(form, json);
            notify(json.message || '操作成功。', 'success');

            /*
             * 必须用 hasAttribute 判断，不能用 getAttribute：
             * 模板里写的是无值的布尔属性 `data-ajax-redirect`，
             * getAttribute() 取到的是空字符串 ""，在 JS 里是 falsy ——
             * 那会让「提交成功后跳转」这个功能永远不触发。
             */
            if (json.redirect && form.hasAttribute('data-ajax-redirect')) {
              window.setTimeout(function () {
                window.location.href = json.redirect;
              }, 400);
            }

            return;
          }

          notify(json.message || '操作失败，请稍后重试。', 'danger');
        })
        .catch(function () {
          notify('网络异常，请检查连接后重试。', 'danger');
        })
        .then(function () {
          if (submitter) {
            submitter.disabled = false;
          }
        });
    });
  }

  /* =======================================================================
   * 4. 移动端导航
   * ===================================================================== */

  function initNavToggle() {
    var toggle = document.querySelector('[data-nav-toggle]');
    var nav = document.querySelector('[data-site-nav]');

    if (!toggle || !nav) {
      return;
    }

    toggle.addEventListener('click', function () {
      var open = nav.hasAttribute('data-open');

      if (open) {
        nav.removeAttribute('data-open');
        toggle.setAttribute('aria-expanded', 'false');
      } else {
        nav.setAttribute('data-open', '');
        toggle.setAttribute('aria-expanded', 'true');
      }
    });

    document.addEventListener('click', function (event) {
      if (!nav.hasAttribute('data-open')) {
        return;
      }

      if (nav.contains(event.target) || toggle.contains(event.target)) {
        return;
      }

      nav.removeAttribute('data-open');
      toggle.setAttribute('aria-expanded', 'false');
    });
  }

  /* =======================================================================
   * 5. 字数统计（textarea[data-counter] 或 .editor 内的 textarea）
   * ===================================================================== */

  function initCounters() {
    var areas = document.querySelectorAll('.editor textarea, textarea[data-counter]');

    Array.prototype.forEach.call(areas, function (area) {
      var group = area.closest('.editor') || area.parentElement;
      var counter = group ? group.querySelector('.char-counter') : null;

      if (!counter) {
        return;
      }

      var max = parseInt(counter.getAttribute('data-max') || '0', 10) || 0;

      function update() {
        var length = Array.from(area.value).length;
        counter.textContent = max > 0 ? length + ' / ' + max : String(length);

        if (max > 0 && length > max) {
          counter.setAttribute('data-over', '1');
        } else {
          counter.removeAttribute('data-over');
        }
      }

      area.addEventListener('input', update);
      update();
    });
  }

  /* =======================================================================
   * 6. 编辑器插入语法（加粗 / 代码 / 引用 / 链接）
   * ===================================================================== */

  function initEditorTools() {
    document.addEventListener('click', function (event) {
      var button = event.target.closest ? event.target.closest('[data-insert]') : null;

      if (!button) {
        return;
      }

      var editor = button.closest('.editor');
      var area = editor ? editor.querySelector('textarea') : null;

      if (!area) {
        return;
      }

      event.preventDefault();

      var kind = button.getAttribute('data-insert');
      var start = area.selectionStart;
      var end = area.selectionEnd;
      var selected = area.value.slice(start, end);
      var before = '';
      var after = '';
      var placeholder = '';
      var cursorOffset = 0;

      if (kind === 'bold') {
        before = '**';
        after = '**';
        placeholder = '加粗文字';
      } else if (kind === 'italic') {
        before = '*';
        after = '*';
        placeholder = '斜体文字';
      } else if (kind === 'code') {
        before = '`';
        after = '`';
        placeholder = '代码';
      } else if (kind === 'quote') {
        before = '> ';
        after = '';
        placeholder = '引用内容';
      } else if (kind === 'codeblock') {
        before = '```\n';
        after = '\n```';
        placeholder = '代码块';
      } else if (kind === 'link') {
        before = '[';
        after = '](https://)';
        placeholder = '链接文字';
        cursorOffset = after.length - 1;
      } else {
        return;
      }

      var text = selected || placeholder;

      area.setRangeText(before + text + after, start, end, 'end');

      // 链接的话把光标定位到 URL 位置，方便直接粘贴
      if (cursorOffset > 0) {
        var position = start + before.length + text.length + cursorOffset;
        area.setSelectionRange(position, position);
      }

      area.focus();
      area.dispatchEvent(new Event('input', { bubbles: true }));
    });
  }

  /* =======================================================================
   * 7. 上传图片预览
   * ===================================================================== */

  function initFilePreview() {
    document.addEventListener('change', function (event) {
      var input = event.target;

      if (!input.matches || !input.matches('input[type="file"][data-preview]')) {
        return;
      }

      var target = document.querySelector(input.getAttribute('data-preview'));
      if (!target) {
        return;
      }

      var file = input.files && input.files[0] ? input.files[0] : null;

      if (!file || file.type.indexOf('image/') !== 0) {
        target.innerHTML = '';
        return;
      }

      var reader = new FileReader();
      reader.onload = function () {
        target.innerHTML = '';
        var img = document.createElement('img');
        img.src = String(reader.result);
        img.alt = '预览';
        img.style.width = '96px';
        img.style.height = '96px';
        img.style.borderRadius = '999px';
        img.style.objectFit = 'cover';
        img.style.border = '1px solid var(--qq-line)';
        target.appendChild(img);
      };
      reader.readAsDataURL(file);
    });
  }

  /* =======================================================================
   * 8. 复制到剪贴板
   * ===================================================================== */

  function initCopy() {
    document.addEventListener('click', function (event) {
      var button = event.target.closest ? event.target.closest('[data-copy]') : null;

      if (!button) {
        return;
      }

      event.preventDefault();

      var text = button.getAttribute('data-copy') || '';

      function fallback() {
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', 'readonly');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();

        try {
          document.execCommand('copy');
          notify('已复制到剪贴板。', 'success');
        } catch (error) {
          notify('复制失败，请手动选择复制。', 'danger');
        }

        document.body.removeChild(area);
      }

      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(function () {
          notify('已复制到剪贴板。', 'success');
        }).catch(fallback);
      } else {
        fallback();
      }
    });
  }

  /* =======================================================================
   * 9. 下拉菜单点击后自动关闭（原生 popover 不会自动收）
   * ===================================================================== */

  function initDropdownAutoClose() {
    document.addEventListener('click', function (event) {
      var item = event.target.closest ? event.target.closest('menu[popover] a, menu[popover] button') : null;

      if (!item) {
        return;
      }

      var popover = item.closest('menu[popover]');
      if (!popover) {
        return;
      }

      // 让浏览器完成默认行为（跳转）后再收起
      window.setTimeout(function () {
        if (typeof popover.hidePopover === 'function' && popover.matches(':popover-open')) {
          popover.hidePopover();
        }
      }, 60);
    });
  }

  /* =======================================================================
   * 10. 内容区外链安全属性
   * ===================================================================== */

  function initExternalLinks() {
    var links = document.querySelectorAll('.floor__body a[href^="http"]');

    Array.prototype.forEach.call(links, function (link) {
      if (link.hostname !== window.location.hostname) {
        link.setAttribute('target', '_blank');
        link.setAttribute('rel', 'noopener noreferrer nofollow');
      }
    });
  }

  /* =======================================================================
   * 11. 返回上一页（错误页使用；CSP 不允许 javascript: 链接）
   * ===================================================================== */

  function initHistoryBack() {
    document.addEventListener('click', function (event) {
      var button = event.target.closest ? event.target.closest('[data-history-back]') : null;

      if (!button) {
        return;
      }

      event.preventDefault();

      if (window.history.length > 1) {
        window.history.back();
      } else {
        window.location.href = '/';
      }
    });
  }

  /* =======================================================================
   * 12. 附件上传（input[type=file][data-upload]）
   * -----------------------------------------------------------------------
   * 流程：选择文件 → 立即上传到 data-upload 指定的接口 → 成功后把返回的附件 ID
   *       以隐藏域 attachments[] 写进表单，并渲染一个可移除的附件标签。
   * 这样发帖/回复表单只提交「附件 ID」，与后端 Request::intArray('attachments') 对齐。
   * ===================================================================== */

  var attachmentSeq = 0;

  /** 把 Markdown 引用复制到剪贴板（clipboard API 不可用时退回 execCommand） */
  function copyText(text, done) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(
        function () { done(true); },
        function () { done(false); }
      );
      return;
    }

    var tmp = document.createElement('textarea');
    tmp.value = text;
    tmp.setAttribute('readonly', '');
    tmp.style.position = 'fixed';
    tmp.style.left = '-9999px';
    document.body.appendChild(tmp);
    tmp.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    document.body.removeChild(tmp);
    done(ok);
  }

  /** 内联 SVG 图标（与服务端 partials/icon.php 同形，供 JS 动态创建按钮使用） */
  var ICON_SVGS = {
    copy: '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2.2"/><path d="M5.2 15H4.4A1.4 1.4 0 0 1 3 13.6V4.4A1.4 1.4 0 0 1 4.4 3h9.2a1.4 1.4 0 0 1 1.4 1.4v.8"/></svg>',
    trash: '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.6 6.2h16.8"/><path d="M8.2 6.2V4.6A1.6 1.6 0 0 1 9.8 3h4.4a1.6 1.6 0 0 1 1.6 1.6v1.6"/><path d="M5.6 6.2l.9 12.6a2.1 2.1 0 0 0 2.1 1.9h6.8a2.1 2.1 0 0 0 2.1-1.9l.9-12.6"/></svg>'
  };

  function iconSvg(name) {
    return ICON_SVGS[name] || '';
  }

  /** 附件的 Markdown 引用（图片自动带 !） */
  function attachmentMarkdown(item) {
    return (item.is_image ? '!' : '') + '[' + item.name + '](' + item.url + ')';
  }

  /**
   * 上传中态：一行一个附件。
   * 名称居左，右侧实时显示「上传中 pct · 速度」；底部细线为进度条。
   */
  function renderUploading(target, fileName) {
    var row = document.createElement('div');
    row.className = 'attachment-row attachment-row--uploading';

    var name = document.createElement('div');
    name.className = 'attachment-row__name';
    var nameText = document.createElement('span');
    nameText.textContent = fileName;
    name.appendChild(nameText);
    name.title = fileName;
    row.appendChild(name);

    var right = document.createElement('div');
    right.className = 'attachment-row__right';
    right.textContent = '上传中 0%';
    row.appendChild(right);

    var bar = document.createElement('div');
    bar.className = 'attachment-row__progress';
    row.appendChild(bar);

    target.appendChild(row);
    syncInsertButtons();

    return {
      // 完成时要用它把「上传中」行从列表里移除（否则会与完成行并存）
      row: row,
      progress: function (pct, speedText) {
        right.textContent = '上传中 ' + pct + '%' + (speedText ? ' · ' + speedText : '');
        bar.style.width = pct + '%';
      },
      fail: function (message) {
        row.classList.add('attachment-row--failed');
        right.textContent = message;
        if (bar.parentNode) {
          bar.parentNode.removeChild(bar);
        }
      }
    };
  }

  /**
   * 完成态：一行一个附件 —— 左名称、右「复制」「删除」两个图标按钮。
   * 复制/删除走事件委托（initUploads 统一处理），编辑页回显的初始行同样生效。
   * attachments[] 隐藏域随行携带，表单提交时一并上报附件 ID。
   */
  function renderAttachment(target, item) {
    if (!target) {
      return;
    }

    var md = attachmentMarkdown(item);

    var row = document.createElement('div');
    row.className = 'attachment-row';
    row.setAttribute('data-markdown', md);
    row.setAttribute('data-attachment', String(item.id));
    /*
     * 附件的原始字段一并挂在行上：草稿自动保存要把「已上传的附件」一起存起来，
     * 恢复时才能原样重建这一行（不重新上传）。
     */
    row.setAttribute('data-att-name', String(item.name || ''));
    row.setAttribute('data-att-size', String(item.size_text || ''));
    row.setAttribute('data-att-image', item.is_image ? '1' : '0');
    row.setAttribute('data-att-url', String(item.url || ''));

    var name = document.createElement('div');
    name.className = 'attachment-row__name';
    var nameText = document.createElement('span');
    nameText.textContent = item.name + (item.size_text ? '（' + item.size_text + '）' : '');
    name.appendChild(nameText);
    name.title = item.name;
    row.appendChild(name);

    var right = document.createElement('div');
    right.className = 'attachment-row__right';
    right.innerHTML =
      '<button type="button" class="attachment-icon-btn" data-attach-copy title="点击复制 Markdown 引用">' + iconSvg('copy') + '</button>' +
      '<button type="button" class="attachment-icon-btn attachment-icon-btn--danger" data-attach-remove title="从列表中移除">' + iconSvg('trash') + '</button>';

    row.appendChild(right);

    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'attachments[]';
    hidden.value = String(item.id);
    row.appendChild(hidden);

    target.appendChild(row);
    syncInsertButtons();
  }

  /**
   * 同步「批量插入」按钮的显隐：没有上传完成的附件时隐藏（用户要求）。
   */
  function syncInsertButtons() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-insert-all]'), function (btn) {
      var target = document.querySelector(btn.getAttribute('data-insert-all') || '');
      if (!target) {
        return;
      }

      var doneCount = target.querySelectorAll(
        '.attachment-row[data-markdown]:not(.attachment-row--uploading):not(.attachment-row--failed)'
      ).length;

      btn.style.display = doneCount > 0 ? '' : 'none';
    });
  }

  /** 「批量插入」：把列表里全部附件的 Markdown 一次性插入正文光标处 */
  function insertAllAttachments(target, textarea) {
    if (!target || !textarea) {
      return;
    }

    var parts = [];
    Array.prototype.forEach.call(target.querySelectorAll('.attachment-row[data-markdown]'), function (row) {
      if (!row.classList.contains('attachment-row--uploading') && !row.classList.contains('attachment-row--failed')) {
        parts.push(row.getAttribute('data-markdown'));
      }
    });

    if (parts.length === 0) {
      notify('还没有上传完成的附件。', 'warning');
      return;
    }

    var start = textarea.selectionStart || 0;
    var end = textarea.selectionEnd || 0;
    var before = textarea.value.slice(0, start);
    var after = textarea.value.slice(end);
    var glue = before !== '' && !/\n$/.test(before) ? '\n\n' : '';
    var text = parts.join('\n\n');

    textarea.value = before + glue + text + after;

    var pos = (before + glue + text).length;
    textarea.selectionStart = textarea.selectionEnd = pos;
    textarea.focus();
    // 让字数统计等依赖 input 事件的组件联动刷新
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function initUploads() {
    /*
     * 复制 / 移除按钮走事件委托：动态创建的行与编辑页回显的初始行统一生效。
     * 移除只从列表拿掉这一行（attachments[] 隐藏域随行消失），不会解除服务端已有的绑定。
     */
    document.addEventListener('click', function (event) {
      if (!event.target || !event.target.closest) {
        return;
      }

      var copyBtn = event.target.closest('[data-attach-copy]');
      if (copyBtn) {
        var rowEl = copyBtn.closest('.attachment-row');
        var md = rowEl ? (rowEl.getAttribute('data-markdown') || '') : '';
        if (md === '') {
          return;
        }

        copyText(md, function (ok) {
          if (ok) {
            notify('Markdown 引用已复制到剪贴板。', 'success');
          } else {
            notify('复制失败，请手动复制。', 'warning');
          }
        });
        return;
      }

      var removeBtn = event.target.closest('[data-attach-remove]');
      if (removeBtn) {
        var rowR = removeBtn.closest('.attachment-row');
        if (rowR && rowR.parentNode) {
          rowR.parentNode.removeChild(rowR);
        }
        syncInsertButtons();
      }
    });

    /* 「上传附件」小图标按钮：代理被隐藏的原生 file input */
    document.addEventListener('click', function (event) {
      var btn = event.target && event.target.closest ? event.target.closest('[data-editor-upload]') : null;
      if (!btn) {
        return;
      }

      var input = document.querySelector(btn.getAttribute('data-editor-upload') || '');
      if (input) {
        input.click();
      }
    });

    /* 「批量插入」按钮走事件委托，多个编辑器共用一份逻辑 */
    document.addEventListener('click', function (event) {
      var btn = event.target && event.target.closest ? event.target.closest('[data-insert-all]') : null;
      if (!btn) {
        return;
      }

      var target = document.querySelector(btn.getAttribute('data-insert-all') || '');
      if (!target) {
        return;
      }

      var editorId = (target.id || '').replace(/^attachments-/, '');
      insertAllAttachments(target, document.getElementById(editorId));
    });

    document.addEventListener('change', function (event) {
      var input = event.target;

      if (!input.matches || !input.matches('input[type="file"][data-upload]')) {
        return;
      }

      var endpoint = input.getAttribute('data-upload');
      var target = document.querySelector(input.getAttribute('data-upload-target') || '');
      var files = input.files ? Array.prototype.slice.call(input.files) : [];

      if (!endpoint || files.length === 0) {
        return;
      }

      input.setAttribute('data-uploading', '1');

      files.forEach(function (file) {
        var body = new FormData();
        body.append('file', file);

        if (csrfToken()) {
          body.append('_token', csrfToken());
        }

        var handle = target ? renderUploading(target, file.name) : null;

        // 用 XHR 而不是 fetch：只有 XHR 的 upload.onprogress 能拿到上传进度
        var xhr = new XMLHttpRequest();
        xhr.open('POST', endpoint);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-OWLSGO-Response', 'json');

        var startedAt = Date.now();

        xhr.upload.addEventListener('progress', function (e) {
          if (e.lengthComputable && handle) {
            var pct = Math.round((e.loaded / e.total) * 100);
            // 实时速度用「自开始以来的平均值」（MB/s），比逐次瞬时值平稳
            var secs = (Date.now() - startedAt) / 1000;
            var speed = secs > 0.2 ? (e.loaded / 1048576 / secs) : 0;
            handle.progress(pct, speed.toFixed(1) + ' MB/s');
          }
        });

        xhr.addEventListener('load', function () {
          var json = {};
          try { json = JSON.parse(xhr.responseText); } catch (e) { json = {}; }

          if (xhr.status >= 200 && xhr.status < 300 && json.ok !== false && json.id) {
            if (handle) {
              // 上传中态行替换为完成态行（含「点击复制」）
              if (handle.row && handle.row.parentNode) {
                handle.row.parentNode.removeChild(handle.row);
              }
              renderAttachment(target, json);
            }
            notify('附件“' + json.name + '”已上传。', 'success');
            return;
          }

          if (handle) {
            handle.fail(json.message || '上传失败');
          } else {
            notify(json.message || '附件上传失败。', 'danger');
          }
        });

        xhr.addEventListener('error', function () {
          if (handle) {
            handle.fail('网络异常');
          } else {
            notify('附件上传失败，请检查网络后重试。', 'danger');
          }
        });

        xhr.send(body);
      });

      // 允许重复选择同一个文件
      input.value = '';
    });
  }

  /* =======================================================================
   * 启动
   * ===================================================================== */

  function boot() {
    initFlash();
    initConfirm();
    initGuestGuard();
    initAvatar();
    initAjaxForms();
    initInstallForm();
    initCaptcha();
    initNavToggle();
    initCounters();
    initEditorTools();
    initFilePreview();
    initCopy();
    initDropdownAutoClose();
    initExternalLinks();
    initHistoryBack();
    initUploads();
    initDrafts();
    initSwitchStates();
    syncInsertButtons();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  // 暴露少量工具函数，便于插件或调试使用
  window.owlsgo = {
    notify: notify,
    csrfToken: csrfToken
  };
})();
