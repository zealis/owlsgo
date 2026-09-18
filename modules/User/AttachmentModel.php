<?php
/**
 * 附件模型
 */

declare(strict_types=1);

namespace Modules\User;

use Core\Database;
use Core\Model;
use Core\Upload;

final class AttachmentModel extends Model
{
    protected static string $table = 'attachments';

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
