<?php
/**
 * 应用引导
 *
 * 只做「把基础设施准备好」这一件事，不包含任何业务逻辑。
 * 由 public/index.php 引用；本地试用时的 router.php 最终也会走到它。
 */

declare(strict_types=1);

/* -------------------------------------------------------------------------- */
/*  1. 基础常量                                                                */
/* -------------------------------------------------------------------------- */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

define('APP_CORE_DIR', APP_ROOT . '/core');
define('APP_MODULES_DIR', APP_ROOT . '/modules');
define('APP_TEMPLATE_DIR', APP_ROOT . '/templates');
define('APP_STORAGE_DIR', APP_ROOT . '/storage');
define('APP_CONFIG_DIR', APP_ROOT . '/config');

/* -------------------------------------------------------------------------- */
/*  2. 自动加载（PSR-4，无 Composer）                                           */
/* -------------------------------------------------------------------------- */

require_once APP_CORE_DIR . '/Autoloader.php';

$owlsgoLoader = new Core\Autoloader();
$owlsgoLoader->addNamespace('Core\\', APP_CORE_DIR);
$owlsgoLoader->addNamespace('Modules\\User\\', APP_MODULES_DIR . '/User');
$owlsgoLoader->addNamespace('Modules\\Forum\\', APP_MODULES_DIR . '/Forum');
$owlsgoLoader->addNamespace('Modules\\Thread\\', APP_MODULES_DIR . '/Thread');
$owlsgoLoader->addNamespace('Modules\\Post\\', APP_MODULES_DIR . '/Post');
$owlsgoLoader->addNamespace('Modules\\Admin\\', APP_MODULES_DIR . '/Admin');
$owlsgoLoader->addNamespace('Modules\\Notice\\', APP_MODULES_DIR . '/Notice');
$owlsgoLoader->addNamespace('Modules\\Install\\', APP_MODULES_DIR . '/Install');
$owlsgoLoader->register();

/* -------------------------------------------------------------------------- */
/*  3. 配置加载                                                                */
/* -------------------------------------------------------------------------- */

// 时区必须在任何时间函数之前设置
$owlsgoTimezone = 'Asia/Shanghai';
if (is_file(APP_CONFIG_DIR . '/app.php')) {
    /** @var mixed $owlsgoAppConfig */
    $owlsgoAppConfig = require APP_CONFIG_DIR . '/app.php';
    if (is_array($owlsgoAppConfig) && isset($owlsgoAppConfig['timezone']) && is_string($owlsgoAppConfig['timezone'])) {
        $owlsgoTimezone = $owlsgoAppConfig['timezone'];
    }
}
date_default_timezone_set($owlsgoTimezone);
mb_internal_encoding('UTF-8');

Core\Config::load('app', APP_CONFIG_DIR . '/app.php');

// 数据库配置优先使用安装向导生成的文件（含真实凭据，位于 storage 下不入库）
$owlsgoDbConfig = APP_STORAGE_DIR . '/config/database.php';
if (!is_file($owlsgoDbConfig)) {
    $owlsgoDbConfig = APP_CONFIG_DIR . '/database.php';
}
Core\Config::load('database', $owlsgoDbConfig);

/* -------------------------------------------------------------------------- */
/*  4. 全局辅助函数                                                            */
/* -------------------------------------------------------------------------- */

require_once APP_CORE_DIR . '/helpers.php';

/* -------------------------------------------------------------------------- */
/*  5. 数据库连接配置（实际连接在首次查询时惰性建立）                             */
/* -------------------------------------------------------------------------- */

Core\Database::configure((array)Core\Config::get('database', []));

/* -------------------------------------------------------------------------- */
/*  6. 运行时目录准备                                                          */
/* -------------------------------------------------------------------------- */

foreach (['cache', 'logs', 'sessions', 'database', 'uploads', 'avatars', 'config'] as $owlsgoDir) {
    $owlsgoPath = APP_STORAGE_DIR . '/' . $owlsgoDir;
    if (!is_dir($owlsgoPath)) {
        @mkdir($owlsgoPath, 0755, true);
    }
}

unset($owlsgoLoader, $owlsgoTimezone, $owlsgoAppConfig, $owlsgoDbConfig, $owlsgoDir, $owlsgoPath);
