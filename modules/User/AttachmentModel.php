<?php
/**
 * 附件模型
 */

declare(strict_types=1);

namespace Modules\User;

use Core\Database;
use Core\Model;
use Core\Permission;
use Core\Upload;

final class AttachmentModel extends Model
{
    protected static string $table = 'attachments';

    /**
     * 某用户已占用的附件空间（字节）
     *
     * 只算**未删除**的附件：软删除后空间立刻释放，不需要额外的回收动作。
     * 头像不在 attachments 表里（走 avatars/ 目录），因此不占这里的配额。
     */
    public static function usedBytes(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        return max(0, (int)Database::value(
            'SELECT COALESCE(SUM(' . Database::identifier('size') . '), 0)'
            . ' FROM ' . Database::identifier('attachments')
            . ' WHERE ' . Database::identifier('user_id') . ' = ?'
            . ' AND ' . Database::identifier('deleted_at') . ' IS NULL',
            [$userId]
        ));
    }

    /**
     * 某用户的附件空间配额（字节，0 = 不限制）
     *
     * 配额挂在**用户组**上（`usergroups.attach_quota_mb`），跟着用户当前的用户组走，
     * 所以管理员调整用户组、或把用户换组，额度立刻生效。
     *
     * @param array<string, mixed>|null $user 用户行（可空，空则视为无配额）
     */
    public static function quotaBytes(?array $user): int
    {
        $mb = UsergroupModel::quotaOf(Permission::groupOf($user));

        return $mb <= 0 ? 0 : $mb * 1048576;
    }

    /**
     * 配额状态快照，供上传校验与界面展示复用
     *
     * @param array<string, mixed>|null $user
     * @return array{used:int, quota:int, remaining:int, unlimited:bool}
     */
    public static function quotaState(?array $user): array
    {
        $userId = (int)($user['id'] ?? 0);
        $used   = self::usedBytes($userId);
        $quota  = self::quotaBytes($user);

        return [
            'used'      => $used,
            'quota'     => $quota,
            'remaining' => $quota <= 0 ? 0 : max(0, $quota - $used),
            'unlimited' => $quota <= 0,
        ];
    }

    /**
     * 记录一次上传
     *
     * @param array<string, mixed> $stored Upload::store 的返回值
     */
    public static function record(int $userId, array $stored, int $threadId = 0, int $postId = 0): int
    {
        return static::create([
            'user_id'   => $userId,
            'thread_id' => $threadId,
            'post_id'   => $postId,
            'name'      => (string)$stored['name'],
            'path'      => (string)$stored['path'],
            'mime'      => (string)$stored['mime'],
            'size'      => (int)$stored['size'],
            'hash'      => (string)$stored['hash'],
            'is_image'  => (bool)$stored['is_image'] ? 1 : 0,
            'downloads' => 0,
            'status'    => 1,
        ]);
    }

    /**
     * 把某批附件绑定到帖子
     *
     * @param list<int> $ids
     */
    public static function bindToPost(array $ids, int $userId, int $threadId, int $postId): int
    {
        if ($ids === []) {
            return 0;
        }

        // 只允许绑定「自己上传且尚未绑定」的附件，防止越权绑定他人文件
        $count = 0;
        foreach ($ids as $id) {
            $count += Database::update(
                'attachments',
                ['thread_id' => $threadId, 'post_id' => $postId, 'updated_at' => time()],
                Database::identifier('id') . ' = ? AND ' . Database::identifier('user_id') . ' = ?'
                . ' AND ' . Database::identifier('post_id') . ' = 0'
                . ' AND ' . Database::identifier('deleted_at') . ' IS NULL',
                [$id, $userId]
            );
        }

        Model::flushRowCache();

        return $count;
    }

    /**
     * 某帖子的附件
     *
     * @return list<array<string, mixed>>
     */
    public static function ofPost(int $postId): array
    {
        if ($postId <= 0) {
            return [];
        }

        return static::query()
            ->where('post_id', $postId)
            ->where('status', 1)
            ->orderBy('id', 'asc')
            ->get();
    }

    /**
     * 整理成编辑器回显需要的字段（editor partial 的 $editorAttachments）
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{id:int,name:string,is_image:bool,size_text:string}>
     */
    public static function forEditor(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'        => (int)($row['id'] ?? 0),
                'name'      => (string)($row['name'] ?? ''),
                'is_image'  => !empty($row['is_image']),
                'size'      => (int)($row['size'] ?? 0),
                'size_text' => format_size((int)($row['size'] ?? 0)),
            ];
        }

        return $out;
    }

    /**
     * 按 sha256 查找一条未删除的附件（上传去重用）
     *
     * 同一物理文件（hash 相同）全站只保留一份记录与一份磁盘文件；
     * 再次上传时直接复用这条记录。
     */
    public static function findByHash(string $hash): ?array
    {
        $hash = strtolower(trim($hash));
        if ($hash === '') {
            return null;
        }

        $rows = Database::select(
            'SELECT * FROM ' . Database::identifier('attachments')
            . ' WHERE ' . Database::identifier('hash') . ' = ?'
            . ' AND ' . Database::identifier('status') . ' = 1'
            . ' AND ' . Database::identifier('deleted_at') . ' IS NULL'
            . ' ORDER BY ' . Database::identifier('id') . ' ASC LIMIT 1',
            [$hash]
        );

        return $rows[0] ?? null;
    }

    /**
     * 批量取多帖的附件（消灭 N+1）
     *
     * @param list<int> $postIds
     * @return array<int, list<array<string, mixed>>>
     */
    public static function mapByPosts(array $postIds): array
    {
        $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds))));

        if ($postIds === []) {
            return [];
        }

        $rows = static::query()
            ->whereIn('post_id', $postIds)
            ->where('status', 1)
            ->orderBy('id', 'asc')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['post_id']][] = $row;
        }

        return $map;
    }

    /**
     * 删除附件（同时删除磁盘文件）
     */
    public static function destroy(int $id): bool
    {
        $row = static::find($id);

        if ($row === null) {
            return false;
        }

        Upload::remove((string)$row['path']);
        static::deleteById($id);

        return true;
    }

    /**
     * 批量删除附件（后台批量操作）
     *
     * 逐条走 destroy()，保证「磁盘文件 + 数据库记录」两处成对处理；
     * 单条失败不影响其它条目，返回成功条数。
     *
     * @param list<int> $ids
     */
    public static function destroyMany(array $ids): int
    {
        $done = 0;

        foreach ($ids as $id) {
            if (static::destroy((int)$id)) {
                $done++;
            }
        }

        return $done;
    }

    /**
     * 彻底删除附件（硬删行，同时删磁盘文件）
     *
     * 与 destroy() 的区别：destroy() 是软删（行留着、只删文件），用于后台「删除附件」；
     * 这里是**回收站彻底删除**，行也要一并清掉，否则会留下指向不存在内容的孤儿记录。
     */
    private static function hardDeleteWhere(string $where, array $bindings): int
    {
        $rows = Database::select(
            'SELECT ' . Database::identifier('id') . ', ' . Database::identifier('path')
            . ' FROM ' . Database::identifier('attachments')
            . ' WHERE ' . $where,
            $bindings
        );

        foreach ($rows as $row) {
            Upload::remove((string)$row['path']);
        }

        $removed = Database::delete('attachments', $where, $bindings);

        Model::flushRowCache();

        return $removed;
    }

    /** 彻底删除某个帖子名下的全部附件（含它下面所有楼层的附件） */
    public static function purgeForThread(int $threadId): int
    {
        if ($threadId <= 0) {
            return 0;
        }

        $q = static fn (string $name): string => Database::identifier($name);

        $removed = self::hardDeleteWhere($q('thread_id') . ' = ?', [$threadId]);

        // 楼层附件：先取该帖下全部楼层 ID，再按 post_id 清（不写成子查询，避免驱动差异）
        $postIds = Database::select(
            'SELECT ' . $q('id') . ' FROM ' . $q('posts') . ' WHERE ' . $q('thread_id') . ' = ?',
            [$threadId]
        );

        $ids = array_map(static fn (array $r): int => (int)$r['id'], $postIds);

        if ($ids !== []) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $removed += self::hardDeleteWhere($q('post_id') . ' IN (' . $marks . ')', $ids);
        }

        return $removed;
    }

    /** 彻底删除某条评论名下的附件 */
    public static function purgeForPost(int $postId): int
    {
        if ($postId <= 0) {
            return 0;
        }

        return self::hardDeleteWhere(Database::identifier('post_id') . ' = ?', [$postId]);
    }

    /**
     * 后台分页列表
     *
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function paginateAll(string $keyword, int $page, int $perPage = 20): array
    {
        $query = static::query();

        if ($keyword !== '') {
            $query->whereContains('name', $keyword);
        }

        return $query->orderBy('id', 'desc')->paginate($perPage, $page);
    }

    /** 附件总数与占用空间 */
    public static function stats(): array
    {
        $row = Database::first(
            'SELECT COUNT(*) AS ' . Database::identifier('total')
            . ', COALESCE(SUM(' . Database::identifier('size') . '), 0) AS ' . Database::identifier('bytes')
            . ' FROM ' . Database::identifier('attachments')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL'
        );

        return [
            'total' => (int)($row['total'] ?? 0),
            'bytes' => (int)($row['bytes'] ?? 0),
        ];
    }
}
