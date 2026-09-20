/**
 * owlsgo · 主题引导脚本（必须在 <head> 里**同步**执行，先于样式表渲染）
 * -----------------------------------------------------------------------
 * 职责：首帧绘制前设好两件事，避免闪白/闪错色：
 *   1. html[data-theme] = light | dark（夜间模式）
 *   2. 日间模式的色系（--qq-blue 系列令牌）。夜间模式**不用色系** ——
 *      夜间是独立的「石墨」配色（--qq-blue:#586779 一族，按钮与悬浮字都是石墨），
 *      与参考实现（color_scheme 插件的 dark 变量）一致：夜间观感跟日间选的色系无关。
 *
 * 偏好全部存 localStorage，登录与否都可用：
 *   owlsgo_theme          auto（跟随系统，默认）| light | dark
 *   owlsgo_scheme         色系 id（默认 classic 经典蓝）
 *   owlsgo_schemes_custom 用户自建色系 JSON 数组 [{id,name,brand,hover,soft}]
 *
 * 后续交互（齿轮切换、装扮页色系管理）在 app.js，通过 window.owlsgoTheme 复用。
 * CSP 禁止内联脚本，所以不能写在 HTML 里。
 */
(function () {
  'use strict';

  var KEY = 'owlsgo_theme';
  var SCHEME_KEY = 'owlsgo_scheme';
  var CUSTOM_KEY = 'owlsgo_schemes_custom';

  /* ---------- 色系 ---------- */
  /* 字段与参考插件一致：brand 主色（按钮/选中底）、hover 悬浮/强调、soft 浅底；
     deep 为 owlsgo 特有（链接/强调文字），经典蓝取现有值保持原样，其余色系取 hover。 */
  var BUILTIN_SCHEMES = {
    classic: { name: '经典蓝', brand: '#00a0e9', hover: '#0086c9', deep: '#005f9e', soft: '#e6f7ff' },
    emerald: { name: '翡翠绿', brand: '#047857', hover: '#065f46', deep: '#065f46', soft: '#ecfdf5' },
    red:     { name: '品牌红', brand: '#fc5531', hover: '#e8380d', deep: '#e8380d', soft: '#fef0ed' },
    violet:  { name: '紫罗兰', brand: '#7c3aed', hover: '#6d28d9', deep: '#6d28d9', soft: '#f5f3ff' },
    pink:    { name: '猛男粉', brand: '#fb7299', hover: '#e45c85', deep: '#e45c85', soft: '#fff0f5' },
    ink:     { name: '雅酷黑', brand: '#24292f', hover: '#111418', deep: '#111418', soft: '#f0f2f5' }
  };

  /** 深底适配：把日间品牌色调到夜间可读的亮度档（过暗提亮、过亮略压，
      中间档保持），色相/饱和度基本不变 —— 夜间模式跟色系时用。 */
  function darkify(hex) {
    var r = parseInt(hex.slice(1, 3), 16) / 255;
    var g = parseInt(hex.slice(3, 5), 16) / 255;
    var b = parseInt(hex.slice(5, 7), 16) / 255;
    var max = Math.max(r, g, b), min = Math.min(r, g, b), h = 0, s, l = (max + min) / 2;
    if (max !== min) {
      var d = max - min;
      s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
      if (max === r) h = (g - b) / d + (g < b ? 6 : 0);
      else if (max === g) h = (b - r) / d + 2;
      else h = (r - g) / d + 4;
      h /= 6;
    } else {
      s = 0;
    }
    if (l < 0.5) l = l + (0.58 - l) * 0.8;        /* 过暗 → 提亮 */
    else if (l > 0.7) l = l - (l - 0.62) * 0.5;   /* 过亮 → 略压 */
    s = Math.min(s, 0.8);
    var q = l < 0.5 ? l * (1 + s) : l + s - l * s;
    var p = 2 * l - q;
    var hue2rgb = function (t) {
      if (t < 0) t += 1;
      if (t > 1) t -= 1;
      if (t < 1 / 6) return p + (q - p) * 6 * t;
      if (t < 1 / 2) return q;
      if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6;
      return p;
    };
    var toHex = function (v) {
      var c = Math.round(Math.min(1, Math.max(0, v)) * 255).toString(16);
      return c.length === 1 ? '0' + c : c;
    };
    return '#' + toHex(hue2rgb(h + 1 / 3)) + toHex(hue2rgb(h)) + toHex(hue2rgb(h - 1 / 3));
  }

  function isColor(v) {
    return typeof v === 'string' && /^#[0-9a-fA-F]{6}$/.test(v);
  }

  function customSchemes() {
    try {
      var raw = JSON.parse(localStorage.getItem(CUSTOM_KEY) || '[]');
      if (!Array.isArray(raw)) return {};
      var out = {};
      raw.forEach(function (s) {
        if (!s || typeof s.id !== 'string' || !/^u_[0-9]+$/.test(s.id)) return;
        var name = String(s.name || '').slice(0, 12);
        if (name === '' || !isColor(s.brand) || !isColor(s.hover) || !isColor(s.soft)) return;
        out[s.id] = { name: name, brand: s.brand, hover: s.hover, deep: s.hover, soft: s.soft, custom: true };
      });
      return out;
    } catch (e) {
      return {};
    }
  }

  function allSchemes() {
    var merged = {};
    Object.keys(BUILTIN_SCHEMES).forEach(function (k) { merged[k] = BUILTIN_SCHEMES[k]; });
    var custom = customSchemes();
    Object.keys(custom).forEach(function (k) { merged[k] = custom[k]; });
    return merged;
  }

  function schemeId() {
    try {
      var id = localStorage.getItem(SCHEME_KEY);
      if (id && allSchemes()[id]) return id;
    } catch (e) { /* ignore */ }
    return 'classic';
  }

  function saveSchemeId(id) {
    try { localStorage.setItem(SCHEME_KEY, id); } catch (e) { /* ignore */ }
  }

  function saveCustomList(list) {
    try {
      localStorage.setItem(CUSTOM_KEY, JSON.stringify(list));
      return true;
    } catch (e) {
      return false;
    }
  }

  /** 新增自定义色系（id 自动生成），成功返回带 id 的色系对象，失败返回 null */
  function addCustomScheme(input) {
    var name = String((input && input.name) || '').trim().slice(0, 12);
    var valid = function (v) { return isColor(v); };
    if (name === '' || !valid(input.brand) || !valid(input.hover) || !valid(input.soft)) {
      return null;
    }
    var list = [];
    try {
      var raw = JSON.parse(localStorage.getItem(CUSTOM_KEY) || '[]');
      if (Array.isArray(raw)) list = raw;
    } catch (e) { /* ignore */ }
    if (list.length >= 20) return null;   /* 防爆：自建色系最多 20 个 */
    var scheme = {
      id: 'u_' + Date.now(),
      name: name,
      brand: input.brand.toLowerCase(),
      hover: input.hover.toLowerCase(),
      soft: input.soft.toLowerCase()
    };
    list.push(scheme);
    return saveCustomList(list) ? scheme : null;
  }

  /** 删除自定义色系（内置色系不可删），返回是否真的删了 */
  function removeCustomScheme(id) {
    if (!/^u_[0-9]+$/.test(String(id))) return false;
    var list = [];
    try {
      var raw = JSON.parse(localStorage.getItem(CUSTOM_KEY) || '[]');
      if (Array.isArray(raw)) list = raw;
    } catch (e) { /* ignore */ }
    var next = list.filter(function (s) { return s && s.id !== id; });
    if (next.length === list.length) return false;
    return saveCustomList(next);
  }

  var SCHEME_VAR_NAMES = ['--qq-blue', '--qq-blue-dark', '--qq-blue-deep', '--qq-soft', '--qq-soft-2', '--secondary'];

  /** 把色系写进 html 的行内变量。
     日间：主色/悬浮/链接/浅底全部取色系原值（经典蓝 = 样式表默认，不用行内变量）。
     夜间：页面底(--qq-page)、卡片(--qq-card)、按钮悬浮(--qq-blue-dark)**保持夜间石墨**，
     其余跟随色系 —— 主色与链接用 darkify() 提到深底可读的亮度档，悬浮/选中软底用
     color-mix 掺入卡片底色。经典蓝夜间不设行内变量（= 样式表里的石墨默认值）。 */
  function applyScheme(theme) {
    var style = document.documentElement.style;
    var scheme = schemeId();
    var reset = function () {
      SCHEME_VAR_NAMES.forEach(function (name) { style.removeProperty(name); });
    };

    /* 经典蓝 = 样式表默认值，无论深浅都不需要行内变量 */
    if (scheme === 'classic' || !allSchemes()[scheme]) {
      reset();
      return;
    }

    if (theme === 'dark') {
      var s = allSchemes()[scheme];
      var brand = darkify(s.brand);
      style.setProperty('--qq-blue', brand);
      style.setProperty('--qq-blue-deep', darkify(s.deep));
      style.setProperty('--qq-soft', 'color-mix(in srgb, ' + brand + ' 26%, #171b27)');
      style.setProperty('--qq-soft-2', 'color-mix(in srgb, ' + brand + ' 18%, #171b27)');
      style.setProperty('--secondary', 'color-mix(in srgb, ' + brand + ' 20%, #171b27)');
      /* ⚠️ 不设 --qq-blue-dark：按钮悬浮保持夜间石墨 #6b7c90（用户指定的排除项） */
      return;
    }

    var sl = allSchemes()[scheme];
    style.setProperty('--qq-blue', sl.brand);
    style.setProperty('--qq-blue-dark', sl.hover);
    style.setProperty('--qq-blue-deep', sl.deep);
    style.setProperty('--qq-soft', sl.soft);
    style.setProperty('--qq-soft-2', 'color-mix(in srgb, ' + sl.soft + ' 55%, #fff)');
    style.setProperty('--secondary', sl.soft);
  }

  /* ---------- 深浅色 ---------- */
  function stored() {
    try {
      var v = localStorage.getItem(KEY);
      return v === 'light' || v === 'dark' ? v : 'auto';
    } catch (e) {
      return 'auto';   /* 隐身模式等场景 localStorage 不可用 → 跟随系统 */
    }
  }

  function systemDark() {
    return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  }

  /** auto → 按系统；其余原样（非法值一律落回 light） */
  function resolve(mode) {
    if (mode === 'auto') {
      return systemDark() ? 'dark' : 'light';
    }
    return mode === 'dark' ? 'dark' : 'light';
  }

  /** 把 html[data-theme] 设为解析结果并套用色系，返回 'light' | 'dark' */
  function apply() {
    var resolved = resolve(stored());
    document.documentElement.setAttribute('data-theme', resolved);
    applyScheme(resolved);
    return resolved;
  }

  apply();

  /* 供 app.js（defer 加载）复用；名字挂在 window.owlsgo 命名空间之外，
     因为它必须在 app.js 之前就可用 */
  window.owlsgoTheme = {
    KEY: KEY,
    SCHEME_KEY: SCHEME_KEY,
    CUSTOM_KEY: CUSTOM_KEY,
    BUILTIN_SCHEMES: BUILTIN_SCHEMES,
    stored: stored,
    resolve: resolve,
    apply: apply,
    systemDark: systemDark,
    schemeId: schemeId,
    saveSchemeId: saveSchemeId,
    addCustomScheme: addCustomScheme,
    removeCustomScheme: removeCustomScheme,
    allSchemes: allSchemes,
    customSchemes: customSchemes,
    applyScheme: applyScheme
  };
})();
