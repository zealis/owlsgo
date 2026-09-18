-- ============================================================================
--  owlsgo-demo 插件建表脚本（MySQL 8.0+）
--  执行时机：后台「启用插件」时由 PluginManager::runInstallSql() 自动执行
--  表名规则：plugin_ + 插件ID（短横线转下划线） + _ + 后缀
--            owlsgo-demo + activity => plugin_owlsgo_demo_activity
--  注意：表名必须与 Core\Plugin::tableName('activity') 完全一致
-- ============================================================================

CREATE TABLE IF NOT EXISTS `plugin_owlsgo_demo_activity` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL DEFAULT 0,
    `username`   VARCHAR(20)  NOT NULL DEFAULT '',
    `kind`       VARCHAR(20)  NOT NULL DEFAULT '',
    `detail`     VARCHAR(255) NOT NULL DEFAULT '',
    `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_plugin_owlsgo_demo_activity_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
