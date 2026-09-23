<?php
/**
 * 点赞模型
 *
 * likes 表使用 (user_id, target, target_id) 唯一索引，
 * 因此「重复点赞」在数据库层就被拒绝，不依赖应用层判断。
 */

declare(strict_types=1);

namespace Modules\User;

use Core\Database;
use Core\Model;

final class LikeModel extends Model
{
    protected static string $table = 'likes';

    /** 点赞没有软删除与时间戳维护 */
    protected static bool $softDelete = false;
    protected static bool $timestamps = false;

    /** 支持的目标类型 */
    public const TARGETS = ['thread', 'post'];

    /**
     * 切换点赞状态
     *
     * @return array{liked:bool, count:int}
     */
    public static function toggle(int $userId, string $target, int $targetId): array
    {
        if ($userId <= 0 || $targetId <= 0 || !in_array($target, self::TARGETS, true)) {
            return ['liked' => false, 'count' => 0];
        }

        /*
         * 「点赞记录（likes）」与「冗余计数（like_count）」必须成对变更：
         * 只成功一半就会留下永远对不上的数据，因此整段放进同一个事务 ——
         * 要么都生效，要么都回滚（见 Core\Database::transaction）。
         */
        return Database::transaction(static function () use ($userId, $target, $targetId): array {
            $exists = (bool)Database::value(
                'SELECT 1 FROM ' . Database::identifier('likes')
                . ' WHERE ' . Database::identifier('user_id') . ' = ?'
                . ' AND ' . Database::identifier('target') . ' = ?'
                . ' AND ' . Database::identifier('target_id') . ' = ?',
                [$userId, $target, $targetId]
            );

            if ($exists) {
                Database::delete(
                    'likes',
                    Database::identifier('user_id') . ' = ? AND ' . Database::identifier('target') . ' = ?'
                    . ' AND ' . Database::identifier('target_id') . ' = ?',
                    [$userId, $target, $targetId]
                );
                $liked = false;
                $delta = -1;
            } else {
                Database::insertIgnore('likes', [
                    'user_id'    => $userId,
                    'target'     => $target,
                    'target_id'  => $targetId,
                    'created_at' => time(),
                ], ['user_id', 'target', 'target_id']);
                $liked = true;
                $delta = 1;
            }

            // 同步冗余计数（原子自增，避免竞态）
            $table = $target === 'thread' ? 'threads' : 'posts';
            self::adjustCounter($table, $targetId, $delta);

            $count = (int)Database::value(
                'SELECT ' . Database::identifier('like_count') . ' FROM ' . Database::identifier($table)
                . ' WHERE ' . Database::identifier('id') . ' = ?',
                [$targetId]
            );

            return ['liked' => $liked, 'count' => max(0, $count)];
        });
    }

    /** 某用户对若干目标的点赞情况 */
    public static function likedMap(int $userId, string $target, array $targetIds): array
    {
        if ($userId <= 0 || $targetIds === []) {
            return [];
        }

        $rows = Database::select(
            'SELECT ' . Database::identifier('target_id') . ' FROM ' . Database::identifier('likes')
            . ' WHERE ' . Database::identifier('user_id') . ' = ? AND ' . Database::identifier('target') . ' = ?'
            . ' AND ' . Database::identifier('target_id') . ' IN (' . Database::placeholders(count($targetIds)) . ')',
            array_merge([$userId, $target], array_values($targetIds))
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['target_id']] = true;
        }

        return $map;
    }

    /** 原子调整计数 */
    private static function adjustCounter(string $table, int $id, int $delta): void
    {
        $column = Database::identifier('like_count');

        Database::execute(
            'UPDATE ' . Database::identifier($table)
            . ' SET ' . $column . ' = ' . $column . ' + ?'
            . ' WHERE ' . Database::identifier('id') . ' = ?',
            [$delta, $id]
        );

        Model::flushRowCache();
    }
}
