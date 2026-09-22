<?php
/**
 * 关键 CSS（critical CSS）：在样式表到达前保证首帧与最终观感一致
 *
 * 只做一件事 —— 把「页面底色 / 文字色」写进首帧（值与 theme.css 的
 * --qq-page / --qq-ink 逐字对应，改色要同步这里）。深色用户由
 * theme-boot.js（先于此段同步执行）设好 html[data-theme]，所以不会闪白。
 *
 * 为什么只内联这一小段而不是整个 theme.css：
 *   - HTML 响应是 private no-cache（导航会重新拉取），把 150KB 样式内联进去
 *     等于每次导航都重新传输一遍完整样式 —— 比「外链 + 7 天强缓存」慢得多；
 *   - 外链样式表带 ?v=<mtime> 版本指纹 + Nginx expires 7d，首次之后零下载。
 */
?>
<style>
html{background:#f4f6fa;color:#1f2329}
html[data-theme="dark"]{background:#0f121a;color:#e2e5ed}
</style>
