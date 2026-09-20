-- ============================================================================
--  owlsgo Forum - MySQL 8.0+ 建表脚本
--  引擎 InnoDB / 字符集 utf8mb4 / 排序规则 utf8mb4_unicode_ci
--  时间字段统一使用 INT UNSIGNED（Unix 时间戳，秒）
-- ============================================================================

-- 用户组表 ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `usergroups` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`            VARCHAR(191) NOT NULL,
    `slug`            VARCHAR(191) NOT NULL,
    `description`     VARCHAR(255) NOT NULL DEFAULT '',
    `color`           VARCHAR(32)  NOT NULL DEFAULT '',
    `icon`            VARCHAR(64)  NOT NULL DEFAULT '',
    `permissions`     LONGTEXT     NULL,
    `attach_quota_mb` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_system`       TINYINT      NOT NULL DEFAULT 0,
    `sort_order`      INT          NOT NULL DEFAULT 0,
    `created_at`      INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at`      INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_usergroups_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 用户表 ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`       VARCHAR(191) NOT NULL,
    `email`          VARCHAR(191) NOT NULL,
    `password_hash`  VARCHAR(255) NOT NULL,
    `group_id`       INT UNSIGNED NOT NULL DEFAULT 1,
    `avatar`         VARCHAR(255) NOT NULL DEFAULT '',
    `signature`      VARCHAR(255) NOT NULL DEFAULT '',
    `bio`            TEXT         NULL,
    `location`       VARCHAR(191) NOT NULL DEFAULT '',
    `public_threads` TINYINT      NOT NULL DEFAULT 1,
    `public_posts`   TINYINT      NOT NULL DEFAULT 1,
    `points`         INT          NOT NULL DEFAULT 0,
    `thread_count`   INT          NOT NULL DEFAULT 0,
    `post_count`     INT          NOT NULL DEFAULT 0,
    `favorite_count` INT          NOT NULL DEFAULT 0,
    `status`         TINYINT      NOT NULL DEFAULT 1,
    `register_ip`    VARCHAR(45)  NOT NULL DEFAULT '',
    `last_login_ip`  VARCHAR(45)  NOT NULL DEFAULT '',
    `last_login_at`  INT UNSIGNED NOT NULL DEFAULT 0,
    `last_active_at` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`     INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at`     INT UNSIGNED NOT NULL DEFAULT 0,
    `deleted_at`     INT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_users_username` (`username`),
    UNIQUE KEY `idx_users_email` (`email`),
    KEY `idx_users_group` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 版块表 ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `forums` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `parent_id`        INT UNSIGNED NOT NULL DEFAULT 0,
    `name`             VARCHAR(191) NOT NULL,
    `slug`             VARCHAR(191) NOT NULL DEFAULT '',
    `description`      VARCHAR(255) NOT NULL DEFAULT '',
    `icon`             VARCHAR(64)  NOT NULL DEFAULT '',
    `announcement`     TEXT         NULL,
    `sort_order`       INT          NOT NULL DEFAULT 0,
    `status`           TINYINT      NOT NULL DEFAULT 1,
    `allow_thread`     TINYINT      NOT NULL DEFAULT 1,
    `allow_reply`      TINYINT      NOT NULL DEFAULT 1,
    `allow_attachment` TINYINT      NOT NULL DEFAULT 1,
    `group_view`       VARCHAR(255) NOT NULL DEFAULT '',
    `group_thread`     VARCHAR(255) NOT NULL DEFAULT '',
    `group_reply`      VARCHAR(255) NOT NULL DEFAULT '',
    `moderators`       VARCHAR(255) NOT NULL DEFAULT '',
    `thread_count`     INT          NOT NULL DEFAULT 0,
    `post_count`       INT          NOT NULL DEFAULT 0,
    `last_thread_id`   INT UNSIGNED NOT NULL DEFAULT 0,
    `last_thread_name` VARCHAR(255) NOT NULL DEFAULT '',
    `last_reply_at`    INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`       INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at`       INT UNSIGNED NOT NULL DEFAULT 0,
    `deleted_at`       INT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_forums_parent` (`parent_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 帖子表 ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `threads` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `forum_id`           INT UNSIGNED NOT NULL,
    `user_id`            INT UNSIGNED NOT NULL,
    `title`              VARCHAR(255) NOT NULL,
    `views`              INT UNSIGNED NOT NULL DEFAULT 0,
    `reply_count`        INT          NOT NULL DEFAULT 0,
    `like_count`         INT          NOT NULL DEFAULT 0,
    `favorite_count`     INT          NOT NULL DEFAULT 0,
    `is_pinned`          TINYINT      NOT NULL DEFAULT 0,
    `is_essence`         TINYINT      NOT NULL DEFAULT 0,
    `is_locked`          TINYINT      NOT NULL DEFAULT 0,
    `is_recommended`     TINYINT      NOT NULL DEFAULT 0,
    `status`             TINYINT      NOT NULL DEFAULT 1,
    `last_reply_at`      INT UNSIGNED NOT NULL DEFAULT 0,
    `last_reply_user_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`         INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at`         INT UNSIGNED NOT NULL DEFAULT 0,
    `deleted_at`         INT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_threads_forum` (`forum_id`, `is_pinned`, `last_reply_at`),
    KEY `idx_threads_user` (`user_id`, `created_at`),
    KEY `idx_threads_status` (`status`, `deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 评论表 ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `posts` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `thread_id`    INT UNSIGNED NOT NULL,
    `forum_id`     INT UNSIGNED NOT NULL DEFAULT 0,
    `user_id`      INT UNSIGNED NOT NULL,
    `parent_id`    INT UNSIGNED NOT NULL DEFAULT 0,
    `floor`        INT          NOT NULL DEFAULT 1,
    `is_first`     TINYINT      NOT NULL DEFAULT 0,
    `content`      LONGTEXT     NOT NULL,
    `content_html` LONGTEXT     NULL,
    `like_count`   INT          NOT NULL DEFAULT 0,
    `status`       TINYINT      NOT NULL DEFAULT 1,
    `ip`           VARCHAR(45)  NOT NULL DEFAULT '',
    `device`       VARCHAR(64)  NOT NULL DEFAULT '',
    `user_agent`   VARCHAR(255) NOT NULL DEFAULT '',
    `created_at`   INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at`   INT UNSIGNED NOT NULL DEFAULT 0,
    `deleted_at`   INT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_posts_thread` (`thread_id`, `floor`),
    KEY `idx_posts_user` (`user_id`, `created_at`),
    KEY `idx_posts_parent` (`parent_id`),
    KEY `idx_posts_status` (`status`, `deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 收藏表 ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `favorites` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `thread_id`  INT UNSIGNED NOT NULL,
    `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_favorites_unique` (`user_id`, `thread_id`),
    KEY `idx_favorites_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 点赞表 ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `likes` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `target`     VARCHAR(32)  NOT NULL,
    `target_id`  INT UNSIGNED NOT NULL,
    `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_likes_unique` (`user_id`, `target`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 附件表 ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attachments` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `thread_id`  INT UNSIGNED NOT NULL DEFAULT 0,
    `post_id`    INT UNSIGNED NOT NULL DEFAULT 0,
    `name`       VARCHAR(255) NOT NULL,
    `path`       VARCHAR(255) NOT NULL,
    `mime`       VARCHAR(128) NOT NULL DEFAULT '',
    `size`       INT UNSIGNED NOT NULL DEFAULT 0,
    `hash`       VARCHAR(64)  NOT NULL DEFAULT '',
    `is_image`   TINYINT      NOT NULL DEFAULT 0,
    `downloads`  INT UNSIGNED NOT NULL DEFAULT 0,
    `status`     TINYINT      NOT NULL DEFAULT 1,
    `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` INT UNSIGNED NOT NULL DEFAULT 0,
    `deleted_at` INT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_attachments_user` (`user_id`),
    KEY `idx_attachments_post` (`post_id`),
    KEY `idx_attachments_hash` (`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 站点设置表 ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key`        VARCHAR(191) NOT NULL,
    `value`      LONGTEXT     NULL,
    `updated_at` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_settings_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 插件表 ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `plugins` (
    `id`           VARCHAR(64)  NOT NULL,
    `name`         VARCHAR(191) NOT NULL,
    `version`      VARCHAR(32)  NOT NULL DEFAULT '1.0.0',
    `description`  VARCHAR(255) NOT NULL DEFAULT '',
    `author`       VARCHAR(191) NOT NULL DEFAULT '',
    `url`          VARCHAR(255) NOT NULL DEFAULT '',
    `enabled`      TINYINT      NOT NULL DEFAULT 0,
    `is_system`    TINYINT      NOT NULL DEFAULT 0,
    `config`       LONGTEXT     NULL,
    `hooks`        VARCHAR(255) NOT NULL DEFAULT '',
    `sort_order`   INT          NOT NULL DEFAULT 0,
    `installed_at` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`   INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at`   INT UNSIGNED NOT NULL DEFAULT 0,
    `deleted_at`   INT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 封禁表 ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bans` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type`       VARCHAR(32)  NOT NULL DEFAULT 'user',
    `value`      VARCHAR(191) NOT NULL,
    `reason`     VARCHAR(255) NOT NULL DEFAULT '',
    `admin_id`   INT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` INT UNSIGNED NOT NULL DEFAULT 0,
    `deleted_at` INT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_bans_type_value` (`type`, `value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 通知表 ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `recipient_id` INT UNSIGNED NOT NULL,
    `sender_id`    INT UNSIGNED NOT NULL DEFAULT 0,
    `kind`         VARCHAR(32)  NOT NULL DEFAULT 'system',
    `content`      VARCHAR(500) NOT NULL DEFAULT '',
    `thread_id`    INT UNSIGNED NOT NULL DEFAULT 0,
    `post_id`      INT UNSIGNED NOT NULL DEFAULT 0,
    `is_read`      TINYINT      NOT NULL DEFAULT 0,
    `created_at`   INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at`   INT UNSIGNED NOT NULL DEFAULT 0,
    `deleted_at`   INT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_notifications_recipient` (`recipient_id`, `is_read`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 限流表 ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bucket`     VARCHAR(191) NOT NULL,
    `hits`       INT          NOT NULL DEFAULT 0,
    `expires_at` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_rate_limits_bucket` (`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 计划任务表 ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cron_tasks` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `plugin`      VARCHAR(64)  NOT NULL DEFAULT '',
    `name`        VARCHAR(191) NOT NULL,
    `interval`    INT UNSIGNED NOT NULL DEFAULT 3600,
    `last_run_at` INT UNSIGNED NOT NULL DEFAULT 0,
    `next_run_at` INT UNSIGNED NOT NULL DEFAULT 0,
    `last_status` VARCHAR(32)  NOT NULL DEFAULT '',
    `run_count`   INT UNSIGNED NOT NULL DEFAULT 0,
    `enabled`     TINYINT      NOT NULL DEFAULT 1,
    `created_at`  INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at`  INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_cron_tasks_unique` (`plugin`, `name`),
    KEY `idx_cron_tasks_next` (`enabled`, `next_run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 计划任务日志表 --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cron_logs` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(191) NOT NULL DEFAULT '',
    `status`     VARCHAR(32)  NOT NULL DEFAULT 'ok',
    `message`    VARCHAR(500) NOT NULL DEFAULT '',
    `duration`   INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_cron_logs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 操作日志表 ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `logs` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL DEFAULT 0,
    `action`     VARCHAR(64)  NOT NULL DEFAULT '',
    `target`     VARCHAR(191) NOT NULL DEFAULT '',
    `detail`     VARCHAR(500) NOT NULL DEFAULT '',
    `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
    `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_logs_user` (`user_id`, `created_at`),
    KEY `idx_logs_action` (`action`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 全站通知（公告）表 -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `notices` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`           VARCHAR(100) NOT NULL DEFAULT '',
    `title`          VARCHAR(200) NOT NULL DEFAULT '',
    `body`           MEDIUMTEXT   NOT NULL,
    `attachment_ids` VARCHAR(500) NOT NULL DEFAULT '',
    `enabled`        TINYINT(1)   NOT NULL DEFAULT 1,
    `sort`           INT UNSIGNED NOT NULL DEFAULT 10,
    `created_at`     INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at`     INT UNSIGNED NOT NULL DEFAULT 0,
    `deleted_at`     INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_notices_sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
