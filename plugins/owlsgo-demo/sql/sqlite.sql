-- ============================================================================
--  owlsgo-demo 插件建表脚本（SQLite）
--  执行时机：后台「启用插件」时由 PluginManager::runInstallSql() 自动执行
--  表名规则：plugin_ + 插件ID（短横线转下划线） + _ + 后缀
--            owlsgo-demo + activity => plugin_owlsgo_demo_activity
--  注意：表名必须与 Core\Plugin::tableName('activity') 完全一致
-- ============================================================================

CREATE TABLE IF NOT EXISTS "plugin_owlsgo_demo_activity" (
    "id"         INTEGER PRIMARY KEY AUTOINCREMENT,
    "user_id"    INTEGER NOT NULL DEFAULT 0,
    "username"   TEXT    NOT NULL DEFAULT '',
    "kind"       TEXT    NOT NULL DEFAULT '',
    "detail"     TEXT    NOT NULL DEFAULT '',
    "created_at" INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS "idx_plugin_owlsgo_demo_activity_created"
    ON "plugin_owlsgo_demo_activity" ("created_at");
