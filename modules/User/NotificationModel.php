<?php
/**
 * 通知模型
 *
 * 通知类型：
 *  - reply    有人回复了我的主题
 *  - mention  有人在帖子里 @ 了我
 *  - quote    有人引用了我的回复
 *  - system   系统通知
 *  - audit    内容审核结果
 */

declare(strict_types=1);

namespace Modules\User;

use Core\Database;
use Core\Model;
use Core\Text;

final class NotificationModel extends Model
{
    protected static string $table = 'notifications';

    /** 通知类型 => 中文名 */
    public const KINDS = [
        'reply'   => '回复',
        'mention' => '提及',
        'quote'   => '引用',
        'system'  => '系统',
        'audit'   => '审核',
    ];

    /**
     * 未读数缓存：请求内避免重复 COUNT(*)
     *
     * 写入通知或标记已读后会被清理，保证同一次请求里读到的数字不会陈旧。
     *
     * @var array<int, int>
     */
    private static array $countCache = [];

    /**
     * 创建一条通知（自己通知自己时自动忽略）
     */
    public static function push(
        int $recipientId,
        int $senderId,
        string $kind,
        string $content,
        int $threadId = 0,
        int $postId = 0
    ): bool {
        if ($recipientId <= 0 || $recipientId === $senderId) {
            return false;
        }

        if (!isset(self::KINDS[$kind])) {
            $kind = 'system';
        }

        static::create([
            'recipient_id' => $recipientId,
            'sender_id'    => $senderId,
            'kind'         => $kind,
            'content'      => mb_substr($content, 0, 480),
            'thread_id'    => $threadId,
            'post_id'      => $postId,
            'is_read'      => 0,
        ]);

        // 新增未读通知，未读数缓存立即失效
        self::flushCount();

        return true;
    }

    /**
     * 全站广播一条系统通知（公告专用）
     *
     * 公告不再在前台页面（布局的 .site-notice、版块的公告块）渲染，
     * 改由通知系统分发给所有正常状态的用户，统一在「通知」里查看。
     *
     * 实现上是一条 `INSERT ... SELECT`，由数据库一次完成写入 ——
     * 若在 PHP 里循环 push()，用户上千时会变成上千次单条插入。
     *
     * @return int 实际写入的通知条数
     */
    public static function broadcastSystem(string $content, string $kind = 'system', int $excludeUserId = 0): int
    {
        $content = mb_substr(trim($content), 0, 480);

        if ($content === '') {
            return 0;
        }

        if (!isset(self::KINDS[$kind])) {
            $kind = 'system';
        }

        $now = time();

        $sql = 'INSERT INTO ' . Database::identifier('notifications')
            . ' (recipient_id, sender_id, kind, content, thread_id, post_id, is_read, created_at, updated_at) '
            . 'SELECT id, 0, ?, ?, 0, 0, 0, ?, ? FROM ' . Database::identifier('users')
            . ' WHERE status = 1 AND deleted_at IS NULL';

        $params = [$kind, $content, $now, $now];

        /*
         * 排除操作者自己。
         * 他刚写完这条公告，再给自己发一条「【公告】…」纯属打扰 ——
         * 而且系统通知的 sender 是 0（系统），他连「这是我自己发的」都看不出来。
         */
        if ($excludeUserId > 0) {
            $sql .= ' AND ' . Database::identifier('id') . ' <> ?';
            $params[] = $excludeUserId;
        }

        $stmt = Database::query($sql, $params);

        // 批量写入后未读数缓存必须失效，否则同一次请求里读到的还是旧值
        self::flushCount();

        return $stmt->rowCount();
    }

    /**
     * 批量通知：回复主题时通知楼主
     */
    public static function notifyThreadAuthor(array $thread, int $senderId, string $excerpt, int $postId): void
    {
        self::push((int)$thread['user_id'], $senderId, 'reply', $excerpt, (int)$thread['id'], $postId);
    }

    /**
     * 解析正文中的 @提及 并通知对应用户
     */
    public static function notifyMentions(string $body, int $senderId, int $threadId, int $postId): void
    {
        $names = Text::mentions($body);

        if ($names === []) {
            return;
        }

        $users = Database::select(
            'SELECT ' . Database::identifier('id') . ',' . Database::identifier('username')
            . ' FROM ' . Database::identifier('users')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL'
            . ' AND ' . Database::identifier('username') . ' IN (' . Database::placeholders(count($names)) . ')',
            $names
        );

        $excerpt = Text::excerpt($body, 100);

        foreach ($users as $user) {
            self::push((int)$user['id'], $senderId, 'mention', $excerpt, $threadId, $postId);
        }
    }

    /**
     * 分页读取某用户的通知
     *
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function forUser(int $userId, int $page, int $perPage = 20): array
    {
        return static::query()
            ->where('recipient_id', $userId)
            ->orderBy('id', 'desc')
            ->paginate($perPage, $page);
    }

    /** 未读数量（用于导航角标） */
    public static function unreadCount(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        if (isset(self::$countCache[$userId])) {
            return self::$countCache[$userId];
        }

        self::$countCache[$userId] = (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('notifications')
            . ' WHERE ' . Database::identifier('recipient_id') . ' = ?'
            . ' AND ' . Database::identifier('is_read') . ' = 0'
            . ' AND ' . Database::identifier('deleted_at') . ' IS NULL',
            [$userId]
        );

        return self::$countCache[$userId];
    }

    /** 清理未读数缓存 */
    public static function flushCount(): void
    {
        self::$countCache = [];
    }

    /** 全部标记为已读 */
    public static function markAllRead(int $userId): int
    {
        $affected = Database::update(
            'notifications',
            ['is_read' => 1, 'updated_at' => time()],
            Database::identifier('recipient_id') . ' = ? AND ' . Database::identifier('is_read') . ' = 0',
            [$userId]
        );

        self::flushCount();
        Model::flushRowCache();

        return $affected;
    }

    /**
     * 通知跳转地址
     *
     * @param array<string, mixed> $notification
     */
    public static function link(array $notification): string
    {
        $threadId = (int)($notification['thread_id'] ?? 0);
        $postId   = (int)($notification['post_id'] ?? 0);

        if ($threadId > 0) {
            return \Core\Router::url('/t/' . $threadId . ($postId > 0 ? '?p=' . $postId : ''));
        }

        return \Core\Router::url('/notifications');
    }
}
