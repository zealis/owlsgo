<?php
/**
 * 收藏模型
 *
 * favorites 表带 (user_id, thread_id) 唯一索引，重复收藏在数据库层即被拒绝。
 */

declare(strict_types=1);

namespace Modules\Thread;

use Core\Database;
use Core\Model;

final class FavoriteModel extends Model
{
    protected static string $table = 'favorites';

    protected static bool $softDelete = false;
    protected static bool $timestamps = false;

    /**
     * 切换收藏状态
     *
     * @return array{favorited:bool, count:int}
     */
    public static function toggle(int $userId, int $threadId): array
    {
        if ($userId <= 0 || $threadId <= 0) {
            return ['favorited' => false, 'count' => 0];
        }

        /*
         * 收藏要同时改三张表：favorites（记录）、threads.favorite_count、
         * users.favorite_count —— 任何一处单独成功都会让计数与记录对不上，
         * 所以整段用事务包起来（要么三张表都写，要么都不写）。
         */
        return Database::transaction(static function () use ($userId, $threadId): array {
            $exists = (bool)Database::value(
                'SELECT 1 FROM ' . Database::identifier('favorites')
                . ' WHERE ' . Database::identifier('user_id') . ' = ? AND ' . Database::identifier('thread_id') . ' = ?',
                [$userId, $threadId]
            );

            if ($exists) {
                Database::delete(
                    'favorites',
                    Database::identifier('user_id') . ' = ? AND ' . Database::identifier('thread_id') . ' = ?',
                    [$userId, $threadId]
                );
                $delta = -1;
                $favorited = false;
            } else {
                Database::insertIgnore('favorites', [
                    'user_id'    => $userId,
                    'thread_id'  => $threadId,
                    'created_at' => time(),
                ], ['user_id', 'thread_id']);
                $delta = 1;
                $favorited = true;
            }

            // 同步帖子冗余计数
            $column = Database::identifier('favorite_count');
            Database::execute(
                'UPDATE ' . Database::identifier('threads')
                . ' SET ' . $column . ' = ' . $column . ' + ?'
                . ' WHERE ' . Database::identifier('id') . ' = ?',
                [$delta, $threadId]
            );

            // 同步用户收藏数
            $userColumn = Database::identifier('favorite_count');
            Database::execute(
                'UPDATE ' . Database::identifier('users')
                . ' SET ' . $userColumn . ' = ' . $userColumn . ' + ?'
                . ' WHERE ' . Database::identifier('id') . ' = ?',
                [$delta, $userId]
            );

            Model::flushRowCache();

            $count = (int)Database::value(
                'SELECT ' . Database::identifier('favorite_count') . ' FROM ' . Database::identifier('threads')
                . ' WHERE ' . Database::identifier('id') . ' = ?',
                [$threadId]
            );

            return ['favorited' => $favorited, 'count' => max(0, $count)];
        });
    }

    /**
     * 用户收藏的帖子（含帖子数据）
     *
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function paginateForUser(int $userId, int $page, int $perPage = 20): array
    {
        $result = static::query()
            ->where('user_id', $userId)
            ->orderBy('id', 'desc')
            ->paginate($perPage, $page);

        $threadIds = array_map(static fn (array $row): int => (int)$row['thread_id'], $result['items']);

        $threads = [];
        if ($threadIds !== []) {
            $rows = Database::select(
                'SELECT * FROM ' . Database::identifier('threads')
                . ' WHERE ' . Database::identifier('id') . ' IN (' . Database::placeholders(count($threadIds)) . ')'
                . ' AND ' . Database::identifier('deleted_at') . ' IS NULL',
                $threadIds
            );

            foreach ($rows as $row) {
                $threads[(int)$row['id']] = $row;
            }
        }

        // 保持收藏时间倒序，过滤掉已被删除的帖子
        $items = [];
        foreach ($result['items'] as $row) {
            $threadId = (int)$row['thread_id'];
            if (!isset($threads[$threadId])) {
                continue;
            }

            $thread = $threads[$threadId];
            $thread['favorited_at'] = (int)$row['created_at'];
            $items[] = $thread;
        }

        $result['items'] = ThreadModel::decorate($items);

        return $result;
    }

    /**
     * 判断用户是否已收藏若干帖子
     *
     * @param list<int> $threadIds
     * @return array<int, bool>
     */
    public static function favoritedMap(int $userId, array $threadIds): array
    {
        if ($userId <= 0 || $threadIds === []) {
            return [];
        }

        $rows = Database::select(
            'SELECT ' . Database::identifier('thread_id') . ' FROM ' . Database::identifier('favorites')
            . ' WHERE ' . Database::identifier('user_id') . ' = ?'
            . ' AND ' . Database::identifier('thread_id') . ' IN (' . Database::placeholders(count($threadIds)) . ')',
            array_merge([$userId], array_values($threadIds))
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['thread_id']] = true;
        }

        return $map;
    }

    /** 用户收藏总数 */
    public static function countForUser(int $userId): int
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('favorites')
            . ' WHERE ' . Database::identifier('user_id') . ' = ?',
            [$userId]
        );
    }
}
