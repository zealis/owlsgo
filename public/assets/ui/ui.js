/* ==========================================================================
   owlsgo · 自研 UI 交互层（ui.js）
   --------------------------------------------------------------------------
   本站唯一的前端交互层：零第三方代码、零依赖。
   （下拉 / 侧栏 / 提示气泡 / 上传 / 提示条；2026-09-23 起替换掉原第三方 UI 库的 JS）

   ⚠️ 写法约束（旧内核，目标 Chromium 66+，与 ui.css 同一套基线）：
     · 经典脚本，**不是 ES module**（旧内核模块支持差、还会被 CSP/CORS 影响）
     · ES5 语法：不用 let/const、箭头函数、模板串、解构、展开、
       `?.`、`??`、class 私有字段、for...of
     · 不用 Custom Elements / Popover API / DataTransfer 之外的现代 API
       —— 全部走事件委托 + `data-*` 属性，旧内核一样能跑
     · 所有交互都在 window.ow 下暴露，供 app.js / 插件调用

   组件与契约：
     ow-dropdown   <ow-dropdown><button popovertarget="id">…</button>
                       <menu popover id="id">…[role=menuitem]…</menu></ow-dropdown>
                   现代内核走原生 popover（我们只补定位与键盘）；旧内核用
                   data-popover-open 自己开关（样式见 ui.css 的 @supports 兜底块）。
     ow-sidebar    [data-ow-sidebar-toggle] 切换 [data-ow-sidebar] 的 .is-open
     ow-upload     <ow-upload><input type=file hidden><div data-files>…</div></ow-upload>
                   点击开文件框、拖放收文件、选中项渲染成可移除徽章，
                   改动后派发原生 change 事件（调用方原有监听不用改）。
     ow-tooltip    把 title 属性转成 data-ow-tooltip（避免原生 tooltip 与自绘气泡重叠）
     ow.toast()    提示条；签名与UI 层的 window.ot.toast 一致，便于迁移
   ========================================================================== */

(function () {
  'use strict';

  var doc = document;

  function closest(el, selector) {
    if (!el) {
      return null;
    }

    // 旧内核没有 Element.closest 也能退化（Chromium 41+ 已有，这里只做保险）
    if (el.closest) {
      return el.closest(selector);
    }

    var node = el;

    while (node && node.nodeType === 1) {
      if (matches(node, selector)) {
        return node;
      }
      node = node.parentNode;
    }

    return null;
  }

  function matches(el, selector) {
    if (!el || el.nodeType !== 1) {
      return false;
    }

    var fn = el.matches || el.msMatchesSelector || el.webkitMatchesSelector;

    return fn ? fn.call(el, selector) : false;
  }

  function each(list, fn) {
    for (var i = 0; i < list.length; i++) {
      fn(list[i], i);
    }
  }

  function queryAll(selector, root) {
    return (root || doc).querySelectorAll(selector);
  }

  /* 原生 Popover API 是否可用（与 ui.css 的 @supports not selector(:popover-open) 对齐） */
  function hasNativePopover() {
    return typeof HTMLElement !== 'undefined'
      && typeof HTMLElement.prototype.showPopover === 'function';
  }

  /* =======================================================================
   * 1. 下拉菜单（ow-dropdown）
   * ===================================================================== */

  function triggerOf(menu) {
    if (!menu || !menu.id) {
      return null;
    }

    return doc.querySelector('[popovertarget="' + menu.id + '"]');
  }

  function placeMenu(menu, trigger) {
    if (!menu || !trigger) {
      return;
    }

    var t = trigger.getBoundingClientRect();
    var m = menu.getBoundingClientRect();

    // 下方放不下就翻到按钮上方；右边放不下就右对齐（与原 ui.css 同一套算法）
    menu.style.top = (t.bottom + m.height > (window.innerHeight || 0) ? t.top - m.height : t.bottom) + 'px';
    menu.style.left = (t.left + m.width > (window.innerWidth || 0) ? t.right - m.width : t.left) + 'px';
  }

  function openMenu(menu, trigger) {
    menu.setAttribute('data-popover-open', '');

    if (trigger) {
      trigger.setAttribute('aria-expanded', 'true');
    }

    // display 刚变成 block，尺寸才是真值 → 先读一次再摆放
    placeMenu(menu, trigger);

    var reposition = function () {
      placeMenu(menu, trigger);
    };

    menu.__owReposition = reposition;
    window.addEventListener('scroll', reposition, true);
    window.addEventListener('resize', reposition);

    var first = menu.querySelector('[role="menuitem"]');

    if (first && first.focus) {
      first.focus();
    }
  }

  function closeMenu(menu, focusTrigger) {
    if (!menu) {
      return;
    }

    var wasOpen = menu.hasAttribute('data-popover-open');

    if (!wasOpen) {
      return;
    }

    menu.removeAttribute('data-popover-open');

    if (menu.__owReposition) {
      window.removeEventListener('scroll', menu.__owReposition, true);
      window.removeEventListener('resize', menu.__owReposition);
      menu.__owReposition = null;
    }

    var trigger = triggerOf(menu);

    if (trigger) {
      trigger.setAttribute('aria-expanded', 'false');

      if (focusTrigger && trigger.focus) {
        trigger.focus();
      }
    }
  }

  function closeAllMenus() {
    each(queryAll('[popover][data-popover-open]'), function (menu) {
      closeMenu(menu, false);
    });
  }

  function initDropdowns() {
    var native = hasNativePopover();

    if (native) {
      // 原生 popover：只补定位与 aria（开合、light dismiss 交给浏览器）
      each(queryAll('[popover]'), function (menu) {
        menu.addEventListener('toggle', function (event) {
          var trigger = triggerOf(menu);

          if (event.newState === 'open') {
            placeMenu(menu, trigger);

            var reposition = function () {
              placeMenu(menu, trigger);
            };

            menu.__owReposition = reposition;
            window.addEventListener('scroll', reposition, true);
            window.addEventListener('resize', reposition);

            if (trigger) {
              trigger.setAttribute('aria-expanded', 'true');
            }
          } else {
            if (menu.__owReposition) {
              window.removeEventListener('scroll', menu.__owReposition, true);
              window.removeEventListener('resize', menu.__owReposition);
              menu.__owReposition = null;
            }

            if (trigger) {
              trigger.setAttribute('aria-expanded', 'false');
            }
          }
        });
      });

      return;
    }

    // 旧内核：popovertarget 是空属性，自己开关
    doc.addEventListener('click', function (event) {
      var trigger = closest(event.target, '[popovertarget]');

      if (trigger) {
        var id = trigger.getAttribute('popovertarget');
        var menu = id ? doc.getElementById(id) : null;

        if (!menu) {
          return;
        }

        var wasOpen = menu.hasAttribute('data-popover-open');

        closeAllMenus();

        if (!wasOpen) {
          openMenu(menu, trigger);
        }

        event.preventDefault();

        return;
      }

      // 点菜单内部不算「点外部」
      if (!closest(event.target, '[popover][data-popover-open]')) {
        closeAllMenus();
      }
    });

    doc.addEventListener('keydown', function (event) {
      var key = event.key;

      if (key === 'Escape' || key === 'Esc') {
        closeAllMenus();
        return;
      }

      var item = closest(event.target, '[role="menuitem"]');

      if (!item) {
        return;
      }

      var menu = closest(item, '[popover]');
      var items = menu ? menu.querySelectorAll('[role="menuitem"]') : [];
      var index = -1;

      for (var i = 0; i < items.length; i++) {
        if (items[i] === item) {
          index = i;
        }
      }

      var next = -1;

      if (key === 'ArrowDown') {
        next = index + 1 >= items.length ? 0 : index + 1;
      } else if (key === 'ArrowUp') {
        next = index - 1 < 0 ? items.length - 1 : index - 1;
      }

      if (next >= 0 && items[next]) {
        event.preventDefault();
        items[next].focus();
      }
    });
  }

  /* =======================================================================
   * 2. 点菜单项后自动收起（原生 popover 也不会自己收）
   * ===================================================================== */

  function initMenuAutoClose() {
    doc.addEventListener('click', function (event) {
      var item = closest(event.target, '[popover] a, [popover] button');

      if (!item) {
        return;
      }

      var menu = closest(item, '[popover]');

      if (!menu) {
        return;
      }

      // 让浏览器先完成默认行为（跳转 / 提交）再收
      window.setTimeout(function () {
        if (typeof menu.hidePopover === 'function' && matches(menu, ':popover-open')) {
          menu.hidePopover();
        }

        closeMenu(menu, false);
      }, 60);
    });
  }

  /* =======================================================================
   * 3. 后台侧栏（[data-ow-sidebar-toggle] → 布局上的 data-ow-sidebar-open）
   * -----------------------------------------------------------------------
   * 开合态放在**布局元素**上（与原 ui.css 一致）：ui.css 用
   * `[data-ow-sidebar-layout][data-ow-sidebar-open]` 收起/展开侧栏，
   * 宽屏（always 形态）与窄屏（抽屉）两套规则共用同一个属性。
   * ===================================================================== */

  function sidebarLayoutOf(el) {
    return closest(el, '[data-ow-sidebar-layout]') || null;
  }

  function initSidebar() {
    doc.addEventListener('click', function (event) {
      var toggle = closest(event.target, '[data-ow-sidebar-toggle]');

      if (toggle) {
        var layout = sidebarLayoutOf(toggle);

        if (layout) {
          var open = !layout.hasAttribute('data-ow-sidebar-open');

          if (open) {
            layout.setAttribute('data-ow-sidebar-open', '');
          } else {
            layout.removeAttribute('data-ow-sidebar-open');
          }

          toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        event.preventDefault();

        return;
      }

      // 点抽屉（侧栏）外面的内容 → 窄屏下自动收起
      var opened = doc.querySelector('[data-ow-sidebar-layout][data-ow-sidebar-open]');

      if (opened && !closest(event.target, '[data-ow-sidebar]') && !closest(event.target, '[data-ow-sidebar-toggle]')) {
        opened.removeAttribute('data-ow-sidebar-open');
      }
    });

    doc.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape' && event.key !== 'Esc') {
        return;
      }

      var opened = doc.querySelector('[data-ow-sidebar-layout][data-ow-sidebar-open]');

      if (opened) {
        opened.removeAttribute('data-ow-sidebar-open');
      }
    });
  }

  /* =======================================================================
   * 4. 勾选行标签（label 包着 checkbox/radio 时要成一行）
   * -----------------------------------------------------------------------
   * 原 ui.css 用 `label:has(input[type=checkbox])` 做这件事 —— :has() 要 Chrome 105，
   * 旧内核没有。这里改成加类（样式在 ui.css 的 label.ow-inline），
   * 现代与旧内核走同一条路径，插件输出的表单也一并覆盖。
   * ===================================================================== */

  function initFieldLabels() {
    each(queryAll('label'), function (label) {
      if (label.classList.contains('ow-inline')) {
        return;
      }

      if (label.querySelector('input[type="checkbox"], input[type="radio"]')) {
        label.classList.add('ow-inline');
      }
    });
  }

  /* =======================================================================
   * 5. 提示气泡（title → data-ow-tooltip）
   * -----------------------------------------------------------------------
   * 原生 title 的气泡无法统一外观，且会和自绘气泡叠成两个；
   * 所以凡是带 title 的可交互元素，统一搬到 data-ow-tooltip（样式在 ui.css）。
   * ===================================================================== */

  function initTooltips() {
    each(queryAll('button[title], a[title], [data-tooltip][title]'), function (el) {
      var text = el.getAttribute('title');

      if (!text) {
        return;
      }

      el.setAttribute('data-ow-tooltip', text);
      el.removeAttribute('title');
    });
  }

  /* =======================================================================
   * 5. 提示条（toast）—— 对外 window.ow.toast(message, title, options)
   * ===================================================================== */

  function toastContainer() {
    var box = doc.querySelector('.ow-toast-container');

    if (!box) {
      box = doc.createElement('div');
      box.className = 'ow-toast-container';
      box.setAttribute('role', 'status');
      box.setAttribute('aria-live', 'polite');
      doc.body.appendChild(box);
    }

    return box;
  }

  function toast(message, title, options) {
    if (!message) {
      return null;
    }

    var opts = options || {};
    var box = toastContainer();
    var el = doc.createElement('div');

    el.className = 'ow-toast';

    if (opts.variant) {
      el.setAttribute('data-ow-variant', opts.variant);
    }

    if (title) {
      var head = doc.createElement('div');
      head.className = 'ow-toast-title';
      head.appendChild(doc.createTextNode(String(title)));
      el.appendChild(head);
    }

    var body = doc.createElement('div');
    body.className = 'ow-toast-message';
    body.appendChild(doc.createTextNode(String(message)));
    el.appendChild(body);

    // 点一下就收（比等着超时更符合直觉）
    el.addEventListener('click', function () {
      remove();
    });

    box.appendChild(el);

    var duration = typeof opts.duration === 'number' ? opts.duration : 2400;
    var timer = null;

    function remove() {
      if (timer) {
        window.clearTimeout(timer);
        timer = null;
      }

      if (el.parentNode) {
        el.parentNode.removeChild(el);
      }
    }

    function start() {
      timer = window.setTimeout(remove, duration);
    }

    function stop() {
      if (timer) {
        window.clearTimeout(timer);
        timer = null;
      }
    }

    // 悬停暂停倒计时（与原 ui.css 行为一致）
    el.addEventListener('mouseenter', stop);
    el.addEventListener('mouseleave', start);

    start();

    return { close: remove };
  }

  /* =======================================================================
   * 6. 文件上传（ow-upload）
   * -----------------------------------------------------------------------
   * 契约与UI 层的 <ot-upload> 一致：点击任意处开文件框、拖放收文件、
   * 选中项渲染成可移除徽章，改动后派发原生 change（调用方监听不用改）。
   * ===================================================================== */

  function filesToInput(input, files) {
    if (typeof DataTransfer === 'undefined') {
      return false;
    }

    try {
      var dt = new DataTransfer();

      for (var i = 0; i < files.length; i++) {
        dt.items.add(files[i]);
      }

      input.files = dt.files;

      return true;
    } catch (e) {
      // 个别内核不允许写 input.files（只读）→ 至少保留原生选择的结果
      return false;
    }
  }

  function currentFiles(input) {
    var out = [];

    if (!input.files) {
      return out;
    }

    for (var i = 0; i < input.files.length; i++) {
      out.push(input.files[i]);
    }

    return out;
  }

  function renderUpload(host, input, out) {
    var files = currentFiles(input);

    if (!out) {
      return;
    }

    // 没有选中文件时，把服务端渲染的原始提示恢复出来
    if (!files.length) {
      if (host.__owEmpty) {
        out.innerHTML = '';
        each(host.__owEmpty, function (node) {
          out.appendChild(node);
        });
      }

      host.removeAttribute('data-has-files');

      return;
    }

    out.innerHTML = '';
    host.setAttribute('data-has-files', '');

    each(files, function (file, index) {
      var badge = doc.createElement('span');
      badge.className = 'ow-badge';

      var name = doc.createElement('span');
      name.appendChild(doc.createTextNode(file.name));
      badge.appendChild(name);

      var remove = doc.createElement('button');
      remove.type = 'button';
      remove.className = 'ow-ghost ow-small';
      remove.setAttribute('aria-label', '移除 ' + file.name);
      remove.appendChild(doc.createTextNode('×'));
      remove.setAttribute('data-ow-upload-remove', String(index));
      badge.appendChild(remove);

      out.appendChild(badge);
    });
  }

  function dispatchChange(input) {
    var event;

    try {
      event = new Event('change', { bubbles: true });
    } catch (e) {
      event = doc.createEvent('HTMLEvents');
      event.initEvent('change', true, false);
    }

    input.dispatchEvent(event);
  }

  function initUploads() {
    each(queryAll('ow-upload'), function (host) {
      var input = host.querySelector('input[type="file"]');

      if (!input) {
        return;
      }

      var out = host.querySelector('[data-files]');

      // 记住服务端渲染的「拖到这里 / 点击选择」提示节点，清空后可恢复
      host.__owEmpty = out ? Array.prototype.slice.call(out.childNodes) : [];

      host.addEventListener('click', function (event) {
        if (input.disabled) {
          return;
        }

        var removeBtn = closest(event.target, '[data-ow-upload-remove]');

        if (removeBtn) {
          event.preventDefault();

          var index = parseInt(removeBtn.getAttribute('data-ow-upload-remove'), 10);
          var kept = currentFiles(input).filter(function (_, i) {
            return i !== index;
          });

          if (filesToInput(input, kept)) {
            renderUpload(host, input, out);
          }

          dispatchChange(input);

          return;
        }

        if (event.target !== input) {
          input.click();
        }
      });

      host.addEventListener('dragover', function (event) {
        if (input.disabled) {
          return;
        }

        event.preventDefault();

        if (event.dataTransfer) {
          event.dataTransfer.dropEffect = 'copy';
        }

        host.setAttribute('data-drag', '');
      });

      host.addEventListener('dragleave', function (event) {
        if (!host.contains(event.relatedTarget)) {
          host.removeAttribute('data-drag');
        }
      });

      host.addEventListener('drop', function (event) {
        if (input.disabled) {
          return;
        }

        event.preventDefault();
        host.removeAttribute('data-drag');

        var dropped = event.dataTransfer && event.dataTransfer.files ? event.dataTransfer.files : null;

        if (!dropped || !dropped.length) {
          return;
        }

        if (filesToInput(input, dropped)) {
          renderUpload(host, input, out);
          dispatchChange(input);
        }
      });

      input.addEventListener('change', function () {
        renderUpload(host, input, out);
      });

      renderUpload(host, input, out);
    });
  }

  /* =======================================================================
   * 装配
   * ===================================================================== */

  function boot() {
    initDropdowns();
    initMenuAutoClose();
    initSidebar();
    initFieldLabels();
    initTooltips();
    initUploads();

    // AJAX 追加的节点也补一遍（弹层、上传区大多是服务端渲染，这里只做兜底）
    if (window.MutationObserver && doc.body) {
      var observer = new MutationObserver(function (records) {
        each(records, function (record) {
          each(record.addedNodes, function (node) {
            if (node && node.nodeType === 1) {
              initUploads();
              initTooltips();
            }
          });
        });
      });

      observer.observe(doc.body, { childList: true, subtree: true });
    }
  }

  window.ow = window.ow || {};
  window.ow.toast = toast;

  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
