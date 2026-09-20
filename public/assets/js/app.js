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
  /*  草稿自动保存（发帖 / 评论）：刷新、离开或误关页面都不丢内容        */
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
    /* 正文容器：帖子页是 .post-content（原 .floor__body，保留兼容插件输出） */
    var links = document.querySelectorAll('.post-content a[href^="http"], .floor__body a[href^="http"]');

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
   * 这样发帖/评论表单只提交「附件 ID」，与后端 Request::intArray('attachments') 对齐。
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
    /*
     * 前端去重指纹：文件名 + 字节数。选文件时先查列表里有没有同指纹的行，
     * 有就直接跳过上传（后端另有 sha256 去重兜底，这里省一次无意义的传输）。
     */
    row.setAttribute('data-att-key', String(item.name || '') + ':' + String(item.size || 0));
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
        /*
         * 前端去重：列表里已有「同名 + 同字节数」的附件时直接跳过，
         * 不再发起上传（真正的内容级去重由后端 sha256 兜底）。
         */
        var fileKey = file.name + ':' + file.size;
        if (target) {
          var exists = Array.prototype.some.call(
            target.querySelectorAll('.attachment-row[data-att-key]'),
            function (row) { return row.getAttribute('data-att-key') === fileKey; }
          );
          if (exists) {
            notify('列表中已有同名同大小的附件“' + file.name + '”，已跳过重复上传。', 'warning');
            return;
          }
        }

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
            if (json.duplicate) {
              // 后端按 sha256 发现同内容文件，复用了已有附件记录
              notify(json.message || '该文件已存在，已复用之前的附件。', 'warning');
            } else {
              notify('附件“' + json.name + '”已上传。', 'success');
            }
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
   * 14. 本地上传安装插件：先开文件选择框，选中后双重风险确认
   * -----------------------------------------------------------------------
   * 插件 = 任意 PHP 代码 = 服务器完全执行权限。上传前连续弹两次确认，
   * 第二次明确要求确认「插件来源可信」，任何一步取消都不发起上传。
   *
   * 交互顺序（踩过坑，别改回去）：
   *   点击按钮 → 打开系统文件选择框 → 选中 .zip → 两次确认 → 提交
   * 早期版本把按钮写成 type="submit"，点击即提交表单，而那时用户还没机会选文件，
   * 于是只弹出「请先选择插件 zip 包」——用户看到的是「点了没反应，只说没选文件」。
   * 所以现在按钮是 type="button"，只负责 file.click()，确认逻辑挂在 change 上。
   * ===================================================================== */

  function initPluginUpload() {
    var form = document.querySelector('form[data-plugin-upload]');
    if (!form) {
      return;
    }

    var file = form.querySelector('[data-plugin-file]');
    if (!file) {
      return;
    }

    var trigger = form.querySelector('[data-plugin-trigger]');

    /* 取消或校验失败后清空选择：浏览器对「重复选中同一个文件」不再派发 change，
       不清空的话用户改完 zip 再选同一个文件名会毫无反应 */
    function resetPick() {
      file.value = '';
    }

    if (trigger) {
      trigger.addEventListener('click', function () {
        file.click();
      });
    }

    file.addEventListener('change', function () {
      if (!file.files || file.files.length === 0) {
        return;
      }

      var name = file.files[0].name || '';

      /* accept=".zip" 只是给选择框的过滤提示，用户可以手动改成「所有文件」，
         这里先挡一道，省得服务端往返一次才报错 */
      if (!/\.zip$/i.test(name)) {
        notify('只支持 .zip 格式的插件包。', 'warning');
        resetPick();
        return;
      }

      uiConfirm(
        '即将安装本地插件包「' + name + '」。' +
        '插件包含任意 PHP 代码，安装即意味着授予其在服务器上完全执行的权限：' +
        '来源不明的插件可能被用于删库、窃取数据或植入后门。' +
        '请仅安装来自可信渠道的插件。是否继续？'
      ).then(function (first) {
        if (!first) {
          resetPick();
          return;
        }

        uiConfirm(
          '再次确认：我已核实该插件的来源可信（作者、下载渠道均可追溯），' +
          '并接受安装带来的安全风险。确认上传？'
        ).then(function (second) {
          if (second) {
            form.submit();   // 原生提交，不再触发本监听器
          } else {
            resetPick();
          }
        });
      });
    });

    /* 兜底：表单被其它途径提交（回车等）时没选文件，给一次提示而不是静默失败 */
    form.addEventListener('submit', function (event) {
      if (!file.files || file.files.length === 0) {
        event.preventDefault();
        notify('请先选择插件 zip 包。', 'warning');
      }
    });
  }

  /* =======================================================================
   * 13.（原「首页页签深链」已删除）
   * -----------------------------------------------------------------------
   * 首页四个页签现在是**链接**（<a href="/?tab=N"> + aria-current="page"）：
   * 点页签就是带 ?tab= 的整页跳转，激活哪一项由服务端按 ?tab= 渲染，
   * 不再需要在客户端补一次 click() 去「修正」页签状态 ——
   * 那段逻辑反而会误伤页面上其它 tablist（querySelectorAll 是全局的）。
   * ===================================================================== */

  /* =======================================================================
   * 13.5 深浅色模式（夜间模式）切换
   * -----------------------------------------------------------------------
   * 偏好与解析逻辑在 theme-boot.js（head 内同步执行，防闪白），挂在
   * window.owlsgoTheme 上；这里只负责**交互**：
   *   - 顶栏齿轮菜单的 [data-theme-toggle]：点一下在浅/深之间手动切换；
   *     文案回填 [data-theme-label] —— 深色时显示「日间模式」、浅色显示「夜间模式」
   *     （「显示的是要去的地方」，太阳/月亮图标由 CSS 按 html[data-theme] 显隐）；
   *   - 「个性装扮」页的 [data-appearance-follow]：开 = 跟随系统（auto），
   *     关 = 固定为当前模式，状态文字写进 [data-appearance-state]；
   *   - 系统深浅变化时（auto 模式下）实时跟随；其它页签改了偏好时（storage 事件）同步。
   * ===================================================================== */

  function initThemeToggle() {
    var theme = window.owlsgoTheme;
    if (!theme) {
      return;   /* theme-boot.js 未加载（理论上不可能）→ 放弃交互，保持 boot 时的状态 */
    }

    function resolved() {
      return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }

    function save(mode) {
      try {
        localStorage.setItem(theme.KEY, mode);
      } catch (e) { /* localStorage 不可用（隐身模式等）→ 只切换不记住 */ }
    }

    function syncUi() {
      var dark = resolved() === 'dark';

      Array.prototype.forEach.call(document.querySelectorAll('[data-theme-label]'), function (el) {
        el.textContent = dark ? '日间模式' : '夜间模式';
      });

      Array.prototype.forEach.call(document.querySelectorAll('[data-appearance-follow]'), function (input) {
        input.checked = theme.stored() === 'auto';
        var row = input.closest('.privacy-row');
        var state = row ? row.querySelector('[data-appearance-state]') : null;
        if (state) {
          state.textContent = theme.stored() === 'auto' ? '跟随系统' : '手动切换';
        }
      });

      /* 让浏览器地址栏 / 系统栏的颜色跟着走（浅色沿用站点设置的主色） */
      var meta = document.querySelector('meta[name="theme-color"]');
      if (meta) {
        if (!meta.getAttribute('data-light')) {
          meta.setAttribute('data-light', meta.getAttribute('content') || '#00A0E9');
        }
        meta.setAttribute('content', dark ? '#0f121a' : meta.getAttribute('data-light'));
      }
    }

    /* 齿轮菜单：手动切换深浅色 */
    document.addEventListener('click', function (event) {
      var btn = event.target.closest ? event.target.closest('[data-theme-toggle]') : null;
      if (!btn) {
        return;
      }

      save(resolved() === 'dark' ? 'light' : 'dark');
      theme.apply();
      syncUi();
    });

    /* 个性装扮页：跟随系统开关 */
    document.addEventListener('change', function (event) {
      var input = event.target.closest ? event.target.closest('[data-appearance-follow]') : null;
      if (!input) {
        return;
      }

      /* 关掉跟随 = 把「当前生效的模式」固定下来（而不是退回某个写死的默认值） */
      save(input.checked ? 'auto' : resolved());
      theme.apply();
      syncUi();
      notify(input.checked ? '夜间模式将跟随系统外观。' : '已改为手动切换深浅色。', 'success');
    });

    /* 系统深浅变化（仅 auto 模式下有意义）与其它页签的偏好改动 */
    var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    if (mq) {
      var onSystemChange = function () {
        if (theme.stored() === 'auto') {
          theme.apply();
          syncUi();
        }
      };
      if (typeof mq.addEventListener === 'function') {
        mq.addEventListener('change', onSystemChange);
      } else if (typeof mq.addListener === 'function') {
        mq.addListener(onSystemChange);   /* 旧 Safari */
      }
    }

    window.addEventListener('storage', function (event) {
      if (event.key === theme.KEY || event.key === theme.SCHEME_KEY || event.key === theme.CUSTOM_KEY) {
        theme.apply();
        syncUi();
        renderSchemeGrid();
      }
    });

    syncUi();
    renderSchemeGrid();
  }

  /* =======================================================================
   * 13.7 图片灯箱（帖子 / 回帖 / 公告正文，适配自参考 image_lightbox 插件）
   * -----------------------------------------------------------------------
   * 作用范围：.post-content（帖子/楼层正文）/ .notice-card__body（公告）里的
   * 正文插图（.content-image）。.floor__body 是旧类名，保留以兼容插件自定义模板。
   * - 页内自适应：长图钳制到一屏内完整可见（CSS max-height + object-fit）；
   * - 小图不放大约看：naturalWidth<120 或 naturalHeight<80 的图不可点；
   * - 灯箱内：滚轮/双击/拖拽/双指捏合自由缩放平移（1~5 倍，带边界回弹），
   *   左右按钮 + 方向键在「同一楼层/公告内的图片画廊」里切换，
   *   标题取图片 alt/title，Esc 关闭、+/-/0 缩放、Tab 焦点圈定。
   * ===================================================================== */

  function initLightbox() {
    const SCOPE = '.post-content, .floor__body, .notice-card__body';
    /* ⚠️ 不能把 SCOPE + ' img' 拼起来用：那会变成「.post-content 本身 或 公告 img」，
       mark() 会把楼层容器当图处理、点击委托命中容器 —— 画廊整体失灵。
       图片匹配必须用完整的 SCOPE_IMG 选择器。 */
    const SCOPE_IMG = '.post-content img, .floor__body img, .notice-card__body img';
    let overlay = null;
    let image = null;
    let caption = null;
    let previousButton = null;
    let nextButton = null;
    let resetButton = null;
    let previousFocus = null;
    let gallery = [];
    let currentIndex = -1;
    let zoom = 1;
    let panX = 0;
    let panY = 0;
    let drag = null;
    let stageBounds = null;
    let previousDocumentOverflow = null;
    const pointers = new Map();
    let pinch = null;
    const minimumWidth = 120;
    const minimumHeight = 80;
    const observedImages = new WeakSet();

    /* 可进灯箱的「真图片」判定：
     *  - data:image/*（排除 SVG，SVG 矢量图无放大意义）；
     *  - 本站附件路由 /attachment/{id} —— 真实上传的图片全走这里，URL **没有扩展名**
     *    （name 里的 .jpg 只在下载头里），早期版本只认扩展名导致线上灯箱完全失效；
     *  - 外链图片按扩展名判断；.svg 一律排除。
     * 头像路径单独排除（isEligible 里）。 */
    const isRasterUrl = source => {
      if (!source) return false;
      if (/^data:image\/(?!svg\+xml)[a-z.+-]+/i.test(source)) return true;
      try {
        const u = new URL(source, location.href);
        if (u.origin === location.origin && /^\/attachment\/\d+\/?(?:[?#].*)?$/.test(u.pathname)) return true;
        if (/\.svg(?:$|[?#])/i.test(u.pathname)) return false;
        return /\.(?:avif|bmp|gif|jpe?g|png|webp)(?:$|[?#])/i.test(u.pathname);
      } catch (error) {
        return /\.(?:avif|bmp|gif|jpe?g|png|webp)(?:$|[?#])/i.test(source)
          && !/(?:^data:image\/svg\+xml|\.svg(?:$|[?#]))/i.test(source);
      }
    };
    const getSource = target => {
      const linkedSource = target.closest('a[href]') ? target.closest('a[href]').href : '';
      return isRasterUrl(linkedSource) ? linkedSource : (target.currentSrc || target.src);
    };
    const isEligible = target => {
      const source = getSource(target);
      if (!target.closest(SCOPE) || target.closest('.avatar-img, .emoji, .emoticon')) return false;
      if (/\/(?:avatar|avatar_upload)(?:[\/_-]|$)/i.test(source) || !isRasterUrl(source)) return false;
      return target.naturalWidth >= minimumWidth && target.naturalHeight >= minimumHeight;
    };
    const updateTransform = () => {
      if (!image) return;
      image.style.setProperty('--image-lightbox-scale', zoom);
      image.style.setProperty('--image-lightbox-pan-x', panX + 'px');
      image.style.setProperty('--image-lightbox-pan-y', panY + 'px');
      image.classList.toggle('image-lightbox-zoomed', zoom > 1);
      resetButton.hidden = zoom === 1;
    };
    const clampPan = () => {
      if (!image || !overlay) return;
      const stage = stageBounds || image.parentElement.getBoundingClientRect();
      const maxX = Math.max(0, (image.clientWidth * zoom - stage.width) / 2);
      const maxY = Math.max(0, (image.clientHeight * zoom - stage.height) / 2);
      panX = Math.max(-maxX, Math.min(maxX, panX));
      panY = Math.max(-maxY, Math.min(maxY, panY));
    };
    const resetView = () => {
      zoom = 1;
      panX = 0;
      panY = 0;
      updateTransform();
    };
    const clearGesture = () => {
      pointers.clear();
      pinch = null;
      drag = null;
      stageBounds = null;
      if (image) image.classList.remove('image-lightbox-dragging');
    };
    const setZoom = (nextZoom, clientX, clientY) => {
      const previousZoom = zoom;
      zoom = Math.max(1, Math.min(5, nextZoom));
      if (zoom === 1) return resetView();
      if (previousZoom === zoom) return;
      const stage = stageBounds || image.parentElement.getBoundingClientRect();
      if (Number.isFinite(clientX) && Number.isFinite(clientY)) {
        const factor = zoom / previousZoom;
        const x = clientX - (stage.left + stage.width / 2);
        const y = clientY - (stage.top + stage.height / 2);
        panX = (panX - x) * factor + x;
        panY = (panY - y) * factor + y;
      }
      clampPan();
      updateTransform();
    };
    const show = index => {
      if (!gallery.length) return;
      currentIndex = (index + gallery.length) % gallery.length;
      const target = gallery[currentIndex];
      resetView();
      image.src = getSource(target);
      image.alt = target.alt || '';
      const text = (target.alt || target.title || '').trim();
      caption.textContent = text;
      caption.hidden = text === '';
      const hasNavigation = gallery.length > 1;
      previousButton.hidden = !hasNavigation;
      nextButton.hidden = !hasNavigation;
    };
    const navigate = offset => show(currentIndex + offset);
    const close = () => {
      if (!overlay || overlay.hidden) return;
      overlay.hidden = true;
      clearGesture();
      image.removeAttribute('src');
      image.alt = '';
      resetView();
      caption.textContent = '';
      gallery = [];
      currentIndex = -1;
      if (previousDocumentOverflow !== null) {
        document.documentElement.style.overflow = previousDocumentOverflow;
        previousDocumentOverflow = null;
      }
      if (previousFocus && previousFocus.focus) previousFocus.focus();
    };
    const ensureOverlay = () => {
      if (overlay) return;
      overlay = document.createElement('div');
      overlay.className = 'image-lightbox-overlay';
      overlay.hidden = true;
      overlay.setAttribute('role', 'dialog');
      overlay.setAttribute('aria-modal', 'true');
      overlay.setAttribute('aria-label', '图片预览');
      const dialog = document.createElement('div');
      dialog.className = 'image-lightbox-dialog';
      const stage = document.createElement('div');
      stage.className = 'image-lightbox-stage';
      image = document.createElement('img');
      image.className = 'image-lightbox-image';
      image.alt = '';
      image.draggable = false;
      caption = document.createElement('div');
      caption.className = 'image-lightbox-caption';
      caption.hidden = true;
      const closeButton = document.createElement('button');
      closeButton.type = 'button';
      closeButton.className = 'image-lightbox-close';
      closeButton.setAttribute('aria-label', '关闭图片预览');
      closeButton.textContent = '\u00d7';
      closeButton.addEventListener('click', close);
      previousButton = document.createElement('button');
      previousButton.type = 'button';
      previousButton.className = 'image-lightbox-nav image-lightbox-prev';
      previousButton.setAttribute('aria-label', '上一张图片');
      previousButton.textContent = '\u2039';
      previousButton.addEventListener('click', () => navigate(-1));
      nextButton = document.createElement('button');
      nextButton.type = 'button';
      nextButton.className = 'image-lightbox-nav image-lightbox-next';
      nextButton.setAttribute('aria-label', '下一张图片');
      nextButton.textContent = '\u203a';
      nextButton.addEventListener('click', () => navigate(1));
      resetButton = document.createElement('button');
      resetButton.type = 'button';
      resetButton.className = 'image-lightbox-reset';
      resetButton.setAttribute('aria-label', '还原缩放');
      resetButton.title = '还原缩放';
      resetButton.textContent = '\u21ba';
      resetButton.hidden = true;
      resetButton.addEventListener('click', resetView);
      image.addEventListener('wheel', event => {
        event.preventDefault();
        setZoom(zoom * (event.deltaY < 0 ? 1.25 : 0.8), event.clientX, event.clientY);
      }, { passive: false });
      image.addEventListener('dblclick', event => {
        event.preventDefault();
        if (zoom > 1) resetView();
        else setZoom(2, event.clientX, event.clientY);
      });
      image.addEventListener('pointerdown', event => {
        if (zoom > 1) event.preventDefault();
        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        image.setPointerCapture(event.pointerId);
        stageBounds = image.parentElement.getBoundingClientRect();
        if (pointers.size === 2) {
          const pair = Array.from(pointers.values());
          pinch = { distance: Math.hypot(pair[1].x - pair[0].x, pair[1].y - pair[0].y), zoom: zoom };
          drag = null;
          image.classList.add('image-lightbox-dragging');
          return;
        }
        if (zoom <= 1) return;
        drag = { id: event.pointerId, x: event.clientX, y: event.clientY, panX: panX, panY: panY };
        image.classList.add('image-lightbox-dragging');
      });
      image.addEventListener('pointermove', event => {
        if (pointers.has(event.pointerId)) pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        if (pinch && pointers.size === 2) {
          const pair = Array.from(pointers.values());
          const distance = Math.hypot(pair[1].x - pair[0].x, pair[1].y - pair[0].y);
          setZoom(pinch.zoom * distance / pinch.distance, (pair[0].x + pair[1].x) / 2, (pair[0].y + pair[1].y) / 2);
          return;
        }
        if (!drag || drag.id !== event.pointerId) return;
        panX = drag.panX + event.clientX - drag.x;
        panY = drag.panY + event.clientY - drag.y;
        clampPan();
        updateTransform();
      });
      const stopDrag = event => {
        pointers.delete(event.pointerId);
        if (pointers.size < 2) {
          pinch = null;
          stageBounds = null;
          if (!drag) image.classList.remove('image-lightbox-dragging');
        }
        if (!drag || drag.id !== event.pointerId) return;
        drag = null;
        image.classList.remove('image-lightbox-dragging');
      };
      image.addEventListener('pointerup', stopDrag);
      image.addEventListener('pointercancel', stopDrag);
      image.addEventListener('lostpointercapture', stopDrag);
      image.addEventListener('dragstart', event => event.preventDefault());
      stage.append(image, previousButton, nextButton);
      dialog.append(stage, caption);
      overlay.append(dialog, resetButton, closeButton);
      overlay.addEventListener('click', event => {
        if (!event.target.closest('.image-lightbox-image, .image-lightbox-close, .image-lightbox-nav, .image-lightbox-reset')) close();
      });
      document.body.appendChild(overlay);
    };
    document.addEventListener('click', event => {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      const target = event.target.closest ? event.target.closest(SCOPE_IMG) : null;
      if (!target || !isEligible(target)) return;
      const source = getSource(target);
      if (!source || !/^https?:/i.test(source)) return;
      event.preventDefault();
      ensureOverlay();
      previousFocus = document.activeElement;
      const content = target.closest(SCOPE);
      gallery = Array.prototype.filter.call(
        content.querySelectorAll('img'),
        item => isEligible(item) && /^https?:/i.test(getSource(item) || '')
      );
      currentIndex = gallery.indexOf(target);
      if (currentIndex < 0) {
        gallery = [target];
        currentIndex = 0;
      }
      show(currentIndex);
      overlay.hidden = false;
      previousDocumentOverflow = document.documentElement.style.overflow;
      document.documentElement.style.overflow = 'hidden';
      overlay.querySelector('.image-lightbox-close').focus();
    });
    document.addEventListener('keydown', event => {
      if (!overlay || overlay.hidden) return;
      if (event.key === 'Tab') {
        const controls = Array.from(overlay.querySelectorAll('button:not([hidden])'));
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      } else if (event.key === 'Escape') close();
      else if (event.key === 'ArrowLeft') {
        event.preventDefault();
        navigate(-1);
      } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        navigate(1);
      } else if (event.key === '+' || event.key === '=') {
        event.preventDefault();
        setZoom(zoom * 1.25);
      } else if (event.key === '-') {
        event.preventDefault();
        setZoom(zoom * 0.8);
      } else if (event.key === '0') {
        event.preventDefault();
        resetView();
      }
    });
    const prepare = img => {
      const update = () => img.classList.toggle('image-lightbox-clickable', isEligible(img));
      update();
      if (observedImages.has(img)) return;
      observedImages.add(img);
      img.addEventListener('load', update);
      img.addEventListener('error', () => img.classList.remove('image-lightbox-clickable'));
    };
    const mark = root => {
      if (root.matches && root.matches('img') && root.closest(SCOPE)) prepare(root);
      if (root.querySelectorAll) {
        Array.prototype.forEach.call(root.querySelectorAll(SCOPE_IMG), prepare);
      }
    };
    const start = () => {
      mark(document);
      if (window.MutationObserver) {
        new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node => {
          if (node.nodeType === Node.ELEMENT_NODE) mark(node);
        }))).observe(document.body, { childList: true, subtree: true });
      }
    };
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', start);
    } else {
      start();
    }
  }

  /* =======================================================================
   * 13.6 色系管理（个性装扮页）
   * -----------------------------------------------------------------------
   * 色系 = {name, brand 主色, hover 悬浮/强调, soft 浅底}，结构与参考
   * color_scheme 插件一致；应用方式是写 html 行内变量（--qq-blue 一族）。
   * 内置 5 套（经典蓝/翡翠绿/品牌红/紫罗兰/雅酷黑），用户可在装扮页自建，
   * 自建色系存 localStorage（owlsgo_schemes_custom），仍只作用于当前浏览器。
   * 夜间模式不用色系（固定石墨），applyScheme() 在夜间会摘掉行内变量。
   * ===================================================================== */

  function renderSchemeGrid() {
    var theme = window.owlsgoTheme;
    var grid = document.querySelector('[data-scheme-grid]');
    if (!theme || !grid) {
      return;   /* 只在个性装扮页渲染 */
    }

    var schemes = theme.allSchemes();
    var active = theme.schemeId();
    var html = '';

    Object.keys(schemes).forEach(function (id) {
      var s = schemes[id];
      html += '<div class="scheme-card' + (id === active ? ' active' : '') + '"' +
        ' data-scheme-id="' + id + '">' +
        '<button type="button" class="scheme-card__pick" data-scheme-pick="' + id + '"' +
        ' aria-pressed="' + (id === active ? 'true' : 'false') + '">' +
        '<span class="swatches" aria-hidden="true">' +
        '<i style="background:' + s.brand + '"></i>' +
        '<i style="background:' + s.hover + '"></i>' +
        '<i style="background:' + s.soft + '"></i>' +
        '</span>' +
        '<span class="scheme-card__name">' + escapeHtml(s.name) + '</span>' +
        '</button>' +
        (s.custom ? '<button type="button" class="scheme-card__del" data-scheme-del="' + id + '"' +
          ' title="删除该色系" aria-label="删除色系 ' + escapeHtml(s.name) + '">×</button>' : '') +
        '</div>';
    });

    grid.innerHTML = html;
  }

  function initSchemeManager() {
    var theme = window.owlsgoTheme;
    if (!theme) {
      return;
    }

    var grid = document.querySelector('[data-scheme-grid]');
    var nameInput = document.querySelector('[data-scheme-name]');
    var brandInput = document.querySelector('[data-scheme-brand]');
    var hoverInput = document.querySelector('[data-scheme-hover]');
    var softInput = document.querySelector('[data-scheme-soft]');
    var createBtn = document.querySelector('[data-scheme-create]');

    if (!grid) {
      return;   /* 不在个性装扮页 */
    }

    /* 选择色系：立即生效 + 持久化。
       ⚠️ 必须用 theme.apply() 按当前深浅模式应用 —— 若写死 applyScheme('light')，
       夜间模式下点色系卡片会把日间浅色变量套上（卡片悬浮出现白色遮盖）。 */
    grid.addEventListener('click', function (event) {
      var pick = event.target.closest ? event.target.closest('[data-scheme-pick]') : null;
      if (pick) {
        var id = pick.getAttribute('data-scheme-pick');
        theme.saveSchemeId(id);
        theme.apply();
        renderSchemeGrid();
        notify('已切换到「' + (theme.allSchemes()[id] || {}).name + '」色系。', 'success');
        return;
      }

      var del = event.target.closest ? event.target.closest('[data-scheme-del]') : null;
      if (del) {
        var delId = del.getAttribute('data-scheme-del');
        var removed = theme.removeCustomScheme(delId);   /* 返回是否删除成功 */
        if (removed) {
          /* 删掉的是正在用的 → 回经典蓝（theme-boot 对失效 id 也会兜底回退） */
          var storedId = '';
          try { storedId = localStorage.getItem(theme.SCHEME_KEY) || ''; } catch (e) { /* ignore */ }
          if (storedId === delId) {
            theme.saveSchemeId('classic');
          }
        }
        theme.apply();
        renderSchemeGrid();
        if (removed) {
          notify('色系已删除。', 'success');
        }
      }
    });

    /* 新增自定义色系 */
    if (createBtn) {
      createBtn.addEventListener('click', function () {
        var name = (nameInput.value || '').trim();
        if (name === '') {
          notify('请先填写色系名称。', 'warning');
          nameInput.focus();
          return;
        }

        var scheme = theme.addCustomScheme({
          name: name,
          brand: brandInput.value,
          hover: hoverInput.value,
          soft: softInput.value
        });

        if (!scheme) {
          notify('色系保存失败，请检查颜色值。', 'error');
          return;
        }

        theme.saveSchemeId(scheme.id);   /* 新建即启用 */
        theme.apply();
        renderSchemeGrid();
        nameInput.value = '';
        notify('色系「' + scheme.name + '」已创建并启用。', 'success');
      });
    }
  }

  /* =======================================================================
   * 启动
   * ===================================================================== */

  /* =======================================================================
   * 20. 后台列表批量操作
   * -----------------------------------------------------------------------
   * 表格每行都有自己的操作表单（通过 / 删除），而 HTML 不允许表单嵌套，
   * 所以批量操作**不套 <form>**：这里收集勾选项，POST 到 [data-bulk-endpoint]，
   * 拿 JSON 结果 → notify → 按返回的 redirect 刷新整页（与服务端 flash 同文案）。
   *
   * 约定：
   *   容器      [data-bulk]（内含 data-bulk-endpoint）
   *   全选      [data-bulk-all]（只作用于**本页**，服务端分页）
   *   行复选框  [data-bulk-item] value = 该行的提交值
   *   动作下拉  [data-bulk-action]，选项可带 data-confirm（用 {n} 占位条数）
   *              与 data-extra（需要额外参数时，值 = 那个字段的 name）
   *   额外控件  [data-bulk-extra="<字段名>"] 包一个 name 相同的 select/input
   * ===================================================================== */

  function initAdminBulk() {
    var bars = document.querySelectorAll('[data-bulk]');

    Array.prototype.forEach.call(bars, function (bar) {
      var panel = bar.closest('.panel') || document;

      var all     = bar.querySelector('[data-bulk-all]');
      var action  = bar.querySelector('[data-bulk-action]');
      var run     = bar.querySelector('[data-bulk-run]');
      var counter = bar.querySelector('[data-bulk-count]');
      var noun    = bar.getAttribute('data-bulk-noun') || '项';
      var extras  = bar.querySelectorAll('[data-bulk-extra]');

      if (!action || !run) {
        return;
      }

      function boxes() {
        /* 过滤掉 disabled 的（如「首帖」行）：全选不该把它算进条数，也不该提交它 */
        return Array.prototype.slice.call(panel.querySelectorAll('[data-bulk-item]')).filter(function (box) {
          return !box.disabled;
        });
      }

      function checked() {
        return boxes().filter(function (box) {
          return box.checked;
        });
      }

      function sync() {
        var picked = checked().length;
        var total  = boxes().length;

        if (counter) {
          counter.textContent = '已选 ' + picked + ' ' + noun;
        }

        if (all) {
          all.checked = picked > 0 && picked === total;
          all.indeterminate = picked > 0 && picked < total;
        }

        run.disabled = picked === 0 || action.value === '';
      }

      /* 额外控件按当前动作显示：如「转移版块」才露出目标版块下拉 */
      function syncExtras() {
        var option = action.options[action.selectedIndex];
        var need   = option ? (option.getAttribute('data-extra') || '') : '';

        Array.prototype.forEach.call(extras, function (extra) {
          var show = need !== '' && extra.getAttribute('data-bulk-extra') === need;
          extra.hidden = !show;
          extra.style.display = show ? '' : 'none';
        });
      }

      panel.addEventListener('change', function (event) {
        var target = event.target;

        if (!target || !target.hasAttribute) {
          return;
        }

        if (target.hasAttribute('data-bulk-item')) {
          sync();
          return;
        }

        if (target === all) {
          boxes().forEach(function (box) {
            box.checked = all.checked;
          });
          sync();
        }
      });

      action.addEventListener('change', function () {
        syncExtras();
        sync();
      });

      run.addEventListener('click', function () {
        var picked = checked();
        var option = action.options[action.selectedIndex];

        if (!option || picked.length === 0) {
          return;
        }

        if (option.value === '') {
          notify('请先选择要执行的批量操作。', 'warning');
          return;
        }

        /*
         * 需要额外参数的动作用「先拦住、不发请求」而不是发一个空参数过去 ——
         * 后者会变成「转移到一个不存在的版块」这种服务端才知道的错误。
         */
        var need       = option.getAttribute('data-extra') || '';
        var extraValue = '';
        var extraField = null;

        if (need !== '') {
          var extra = bar.querySelector('[data-bulk-extra="' + need + '"]');
          extraField = extra ? extra.querySelector('select, input, textarea') : null;
          extraValue = extraField ? String(extraField.value || '') : '';

          if (extraValue === '' || extraValue === '0') {
            notify('请先选择「' + option.textContent.trim() + '」的目标。', 'warning');
            return;
          }
        }

        var template = option.getAttribute('data-confirm') || '';
        var message  = template !== ''
          ? template.split('{n}').join(String(picked.length))
          : '确认对选中的 ' + picked.length + ' ' + noun + '执行该操作吗？';

        uiConfirm(message).then(function (ok) {
          if (!ok) {
            return;
          }

          var body = new FormData();
          body.append('_token', csrfToken());
          body.append('action', option.value);

          picked.forEach(function (box) {
            body.append('items[]', box.value);
          });

          if (extraField) {
            body.append(extraField.name || need, extraValue);
          }

          run.disabled = true;

          fetch(bar.getAttribute('data-bulk-endpoint'), {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'X-OWLSGO-Response': 'json'
            }
          })
            .then(function (response) {
              return response.json().catch(function () {
                return {};
              });
            })
            .then(function (json) {
              notify(json.message || '操作完成。', json.ok === false ? 'error' : 'success');

              if (json.ok === false) {
                run.disabled = false;
                return;
              }

              window.setTimeout(function () {
                window.location.href = json.redirect || window.location.href;
              }, 600);
            })
            .catch(function () {
              notify('网络异常，操作未完成。', 'error');
              run.disabled = false;
            });
        });
      });

      syncExtras();
      sync();
    });
  }

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
    initThemeToggle();
    initSchemeManager();
    initLightbox();
    initPluginUpload();
    initAdminBulk();
    syncInsertButtons();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  // 暴露少量工具函数，便于插件或调试使用
  function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  window.owlsgo = {
    notify: notify,
    csrfToken: csrfToken,
    escapeHtml: escapeHtml
  };
})();
