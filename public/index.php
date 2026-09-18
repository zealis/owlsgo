<?php
/**
 * 唯一 Web 入口
 *
 * 所有请求都经过这里：先完成引导，再由核心引擎分发到具体控制器。
 * 除 public/ 目录外的所有文件都不应对外暴露（见根目录 .htaccess / nginx 配置示例）。
 *
 * 兼容形态：本文件同时支持
 *   - 伪静态：/t/12          （需要 URL 重写）
 *   - 兼容：  /index.php?r=/t/12
 * 二者由 Core\Router 自动探测，无需修改配置。
 */

declare(strict_types=1);

// 生产环境错误由 Core\App 统一接管，这里先关闭显示，避免引导阶段泄露路径
ini_set('display_errors', '0');

require dirname(__DIR__) . '/core/bootstrap.php';

Core\Router::load(APP_CONFIG_DIR . '/routes.php');

Core\App::run();
