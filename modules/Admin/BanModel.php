<?php
/**
 * 封禁模型
 *
 * 支持三种封禁维度：
 *  - user  按用户 ID（账号级）
 *  - ip    按 IP（网络级，注册与发帖都会被拦）
 *  - email 按邮箱（防止换号重注册）
 *
 * expires_at = 0 表示永久封禁。
 */

declare(strict_types=1);

namespace Modules\Admin;

use Core\Ban;
use Core\Database;
use Core\Model;

final class BanModel extends Model
{
    protected static string $table = 'bans';

    /** 支持的封禁类型 */
    public const TYPES = [
        'user'  => '用户',
        'ip'    => 'IP',
        'email' => '邮箱',
    ];

    /**
     * 添加封禁记录（同一 type+value 已存在则更新）
     *
     * @return array{ok:bool, message:string, id:int}
     */
    public static function add(string $type, string $value, string $reason, int $expiresAt, int $adminId): array
    {
        if (!isset(self::TYPES[$type])) {
            return ['ok' => false, 'message' => '不支持的封禁类型。', 'id' => 0];
        }

        $value = trim($value);

        if ($value === '') {
            return ['ok' => false, 'message' => '请填写要封禁的对象。', 'id' => 0];
        }

        if ($type === 'ip' && !filter_var($value, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'message' => 'IP 地址格式不正确。', 'id' => 0];
        }

        if ($type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => '邮箱格式不正确。', 'id' => 0];
        }

        if ($type === 'user' && !preg_match('/^\d+$/', $value)) {
            return ['ok' => false, 'message' => '用户封禁需要提供用户 ID。', 'id' => 0];
        }

        $now      = time();
        $existing = self::active($type, $value);

        if ($existing !== null) {
            static::updateById((int)$existing['id'], [
                'reason'     => mb_substr($reason, 0, 200),
                'admin_id'   => $adminId,
                'expires_at' => $expiresAt,
            ]);

            Ban::flush();

            return ['ok' => true, 'message' => '封禁记录已更新。', 'id' => (int)$existing['id']];
        }

        $id = static::create([
            'type'       => $type,
            'value'      => mb_substr($value, 0, 191),
            'reason'     => mb_substr($reason, 0, 200),
            'admin_id'   => $adminId,
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Ban::flush();

        return ['ok' => true, 'message' => '已加入封禁名单。', 'id' => $id];
    }

    /**
     * 查一条生效中的封禁记录
     *
     * @return array<string, mixed>|null
     */
    public static function active(string $type, string $value, bool $includeExpired = false): ?array
    {
        $query = static::query()->where('type', $type)->where('value', $value);

        if (!$includeExpired) {
            $now = time();
            $query->whereRaw(
                '(' . Database::identifier('expires_at') . ' = 0 OR ' . Database::identifier('expires_at') . ' > ?)',
                [$now]
            );
        }

        return $query->orderBy('id', 'desc')->first();
    }

    /**
     * 解除封禁
     */
    public static function revoke(int $id): bool
    {
        $row = static::find($id);

        if ($row === null) {
            return false;
        }

        static::deleteById($id);
        Ban::flush();

        return true;
    }

    /** 按用户 ID 解除封禁 */
    public static function revokeUser(int $userId): bool
    {
        $row = self::active('user', (string)$userId, true);

        if ($row === null) {
            return false;
        }

        return self::revoke((int)$row['id']);
    }

    /**
     * 分页列表
     *
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function paginate(string $type, string $keyword, int $page, int $perPage = 30): array
    {
        $query = static::query();

        if (isset(self::TYPES[$type])) {
            $query->where('type', $type);
        }

        if ($keyword !== '') {
            $query->whereContains('value', $keyword);
        }

        return $query->orderBy('id', 'desc')->paginate($perPage, $page);
    }

    /** 生效中的封禁总数 */
    public static function activeCount(): int
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('bans')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL'
            . ' AND (' . Database::identifier('expires_at') . ' = 0 OR ' . Database::identifier('expires_at') . ' > ?)',
            [time()]
        );
    }
}
