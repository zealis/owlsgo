<?php
/**
 * PHP 内置服务器路由脚本（仅供本地试用，正式部署时无需此文件）
 *
 * 用法：
 *   cd forum
 *   php -S 127.0.0.1:8080 -t public router.php
 *
 * 为什么需要它：内置服务器没有 URL 重写能力，不指定路由脚本时伪静态地址
 * （如 /t/12）会直接 404。本脚本对静态资源返回 false（交回内置服务器处理），
 * 其余请求一律转发给唯一入口 public/index.php，从而让伪静态在本地也能用。
 *
 * 若不想使用本脚本，也可以在 config/app.php 中设置 'pretty_url' => false，
 * 全站改用 /index.php?r=/t/12 兼容形态。
 */

declare(strict_types=1);

$docRoot = str_replace('\\', '/', rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'));

// 防呆：必须用 -t public 启动，否则基础路径探测会得到错误结果
if (basename($docRoot) !== 'public') {
    header('Content-Type: text/plain; charset=utf-8');

    echo "启动方式不正确：请把站点根目录指向 public/\n\n";
    echo "  cd forum\n";
    echo "  php -S 127.0.0.1:8080 -t public router.php\n";

    return;
}

$path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$file = __DIR__ . '/public' . $path;

// 真实存在的静态资源交回内置服务器处理
if ($path !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
    return false;
}

require __DIR__ . '/public/index.php';
