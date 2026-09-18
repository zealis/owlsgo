<?php
/**
 * 封禁检查
 *
 * 支持按用户、IP、邮箱三种维度封禁；expires_at = 0 表示永久。
 * 判定结果在单次请求内缓存，避免同一请求反复查库。
 */

declare(strict_types=1);

namespace Core;

final class Ban
{
    /** @var array<string, array<string, mixed>|false> 判定缓存 */
    private static array $cache = [];

    /**
     * 用户是否处于封禁状态
     *
     * @param array<string, mixed> $user
     */
    public static function isBanned(array $user): bool
    {
        return self::match($user) !== null;
    }

    /** 封禁提示文案 */
    public static function message(array $user): string
    {
        $ban = self::match($user);

        if ($ban === null) {
            return '';
        }

        $reason  = trim((string)($ban['reason'] ?? ''));
        $expires = (int)($ban['expires_at'] ?? 0);

        $suffix = $expires > 0
            ? '，解封时间：' . date('Y-m-d H:i', $expires)
            : '，永久封禁';

        return '你的账号已被封禁' . $suffix . ($reason !== '' ? '。原因：' . $reason : '。');
    }

    /**
     * 命中封禁记录则返回该记录，否则 null
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>|null
     */
    public static function match(array $user): ?array
    {
        $userId = (int)($user['id'] ?? 0);
        $key    = 'user:' . $userId . ':' . Request::ip();

        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key] === false ? null : self::$cache[$key];
        }

        try {
            $now = time();

            // 依次匹配：用户 ID > IP > 邮箱
            $conditions = [
                ['user', (string)$userId],
                ['ip', Request::ip()],
            ];

            $email = trim((string)($user['email'] ?? ''));
            if ($email !== '') {
                $conditions[] = ['email', $email];
            }

            foreach ($conditions as [$type, $value]) {
                if ($value === '' || $value === '0') {
                    continue;
                }

                $row = Database::first(
                    'SELECT * FROM ' . Database::identifier('bans')
                    . ' WHERE ' . Database::identifier('type') . ' = ? AND ' . Database::identifier('value') . ' = ?'
                    . ' AND ' . Database::identifier('deleted_at') . ' IS NULL'
                    . ' AND (' . Database::identifier('expires_at') . ' = 0 OR ' . Database::identifier('expires_at') . ' > ?)'
                    . ' LIMIT 1',
                    [$type, $value, $now]
                );

                if ($row !== null) {
                    self::$cache[$key] = $row;

                    return $row;
                }
            }
        } catch (\Throwable $e) {
            Logger::warning('封禁检查失败：' . $e->getMessage());
        }

        self::$cache[$key] = false;

        return null;
    }

    /**
     * 当前 IP 是否被禁止注册/发帖
     */
    public static function isIpBanned(string $ip = ''): bool
    {
        $ip = $ip !== '' ? $ip : Request::ip();

        try {
            $row = Database::first(
                'SELECT 1 FROM ' . Database::identifier('bans')
                . ' WHERE ' . Database::identifier('type') . " = 'ip'"
                . ' AND ' . Database::identifier('value') . ' = ?'
                . ' AND ' . Database::identifier('deleted_at') . ' IS NULL'
                . ' AND (' . Database::identifier('expires_at') . ' = 0 OR ' . Database::identifier('expires_at') . ' > ?)'
                . ' LIMIT 1',
                [$ip, time()]
            );

            return $row !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /** 清除判定缓存（封禁记录变更后调用） */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
