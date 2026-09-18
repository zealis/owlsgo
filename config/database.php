<?php
/**
 * 数据库配置模板
 *
 * 优先级（高 → 低）：
 *   1. storage/config/database.php   —— 由安装向导生成，含真实凭据，位于 storage 下不入库
 *   2. 环境变量（OWLSGO_DB_*）
 *   3. 本文件中的默认值（仅用于本地开发，且不含任何真实密码）
 *
 * 请勿把真实密码写进本文件并提交到版本库。
 */

declare(strict_types=1);

/** 读取环境变量，未设置时返回默认值 */
$env = static function (string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : (string)$value;
};

return [
    /* sqlite | mysql | pgsql */
    'driver' => $env('OWLSGO_DB_DRIVER', 'sqlite'),

    /* SQLite：数据库文件名，实际路径落在 storage/database/ 下 */
    'database' => $env('OWLSGO_DB_NAME', 'owlsgo.sqlite'),

    /* MySQL / PostgreSQL 连接信息 */
    'host'     => $env('OWLSGO_DB_HOST', '127.0.0.1'),
    'port'     => (int)$env('OWLSGO_DB_PORT', '0'),   // 0 表示按引擎取默认端口
    'username' => $env('OWLSGO_DB_USER', ''),
    'password' => $env('OWLSGO_DB_PASS', ''),

    /*
     * 说明：为了与 sql/*.sql 建表脚本保持字面一致、避免前缀替换带来的隐患，
     * 本系统不使用表前缀。如需在同库部署多套论坛，请使用不同的数据库或 schema。
     */
];
