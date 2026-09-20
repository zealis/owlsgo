<?php
/**
 * 全站通知（公告）模型
 *
 * 通知中心的内容载体：一个站点可以有多条公告，由拥有 notice.manage
 * （用户组里的「发布公告」）权限的用户在通知中心维护。
 *
 * 设计要点：
 *  - 公告内容用与帖子相同的 Markdown 渲染（Core\Text::toHtml），
 *    因此正文里能放图片、链接、代码块；
 *  - 附件不走「正文里写哈希」那套隐式引用，而是像发帖一样由表单提交附件 ID，
 *    序列化进 attachment_ids 字段 —— 与本项目的附件机制（attachments 表 + 绑定）
 *    保持一致，附件管理页据此统计引用关系；
 *  - 软删除：复用 Model 基类的 deleted_at。
 */

declare(strict_types=1);

namespace Modules\Notice;

use Core\Database;
use Core\Model;
use Core\PluginManager;

final class NoticeModel extends Model
{
    protected static string $table = 'notices';

    /** 新建公告的默认排序值（数字越小越靠前） */
    public const DEFAULT_SORT = 10;

    /**
     * 本表是否已确认可用（单次请求内只检查一遍，避免每次查询都问表结构）
     */
    private static ?bool $tableReady = null;

    /**
     * 惰性建表
     *
     * notices 表是随「通知中心」功能一起上线的，全新安装会在导入 schema 时建好；
     * 但**已装好的老站点**不会重跑安装，库里没有这张表。这里在使用前补建一次，
     * 免去让用户重装（那会丢数据）。
     */
    private static function ensureTable(): void
    {
        if (self::$tableReady === true) {
            return;
        }

        if (!Database::tableExists(static::$table)) {
            self::migrate();
        }

        self::$tableReady = true;
    }

    /**
     * 从 sql/{driver}.sql 里摘出与本表相关的语句执行
     *
     * 复用安装器的语句切分逻辑，保证三种数据库方言各用各的建表写法，
     * 不在模型里再维护一份 DDL。
     */
    private static function migrate(): void
    {
        $file = APP_ROOT . '/sql/' . Database::driver() . '.sql';

        if (!is_file($file)) {
            return;
        }

        foreach (PluginManager::splitStatements((string)file_get_contents($file)) as $statement) {
            // 只跑本表的语句；注意 "notifications" 不含子串 "notices"，不会误伤
            if (stripos($statement, static::$table) === false) {
                continue;
            }

            try {
                Database::statement($statement);
            } catch (\Throwable $e) {
                // 并发请求可能已抢先建好，忽略即可
            }
        }
    }

    /**
     * 通知中心前台可见的公告（enabled = 1）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function published(): array
    {
        self::ensureTable();

        return self::withFiles(Database::select(
            'SELECT * FROM ' . Database::identifier(static::$table)
            . ' WHERE ' . Database::identifier('enabled') . ' = 1'
            . ' AND ' . Database::identifier('deleted_at') . ' IS NULL'
            . ' ORDER BY ' . Database::identifier('sort') . ' ASC, '
            . Database::identifier('id') . ' DESC'
        ));
    }

    /**
     * 通知中心前台可见的公告（enabled = 1）——分页版
     *
     * 与 published() 的差别只是「限制一页显示多少条」：条数取自站点统一的
     * config('app.per_page')，跟首页、版块页、我的通知是同一套配置。
     * 公告数量少于每页条数时 Paginator 不会输出分页条，界面与不分页时完全一致，
     * 所以可以直接替换原来「一次性全部列出」的做法。
     *
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function paginatePublished(int $page, int $perPage): array
    {
        self::ensureTable();

        $result = static::query()
            ->where('enabled', 1)
            ->orderBy('sort', 'asc')
            ->orderBy('id', 'desc')
            ->paginate($perPage, $page);

        $result['items'] = self::withFiles($result['items']);

        return $result;
    }

    /** 已发布公告的总数（含未公开？不含：只有 enabled = 1 的） */
    public static function publishedCount(): int
    {
        self::ensureTable();

        return (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier(static::$table)
            . ' WHERE ' . Database::identifier('enabled') . ' = 1'
            . ' AND ' . Database::identifier('deleted_at') . ' IS NULL'
        );
    }

    /**
     * 给一批公告附加各自的附件明细（写入 attachments 键）
     *
     * 一条查询把所有引用到的附件取回来再按 ID 分发，避免逐条公告查一次（N+1）。
     *
     * @param  array<int, array<string, mixed>> $notices
     * @return array<int, array<string, mixed>>
     */
    private static function withFiles(array $notices): array
    {
        $allIds = [];

        foreach ($notices as $notice) {
            foreach (self::attachmentIds($notice) as $id) {
                $allIds[$id] = $id;
            }
        }

        $files = [];

        if ($allIds !== []) {
            $in = Database::placeholders(count($allIds));

            $rows = Database::select(
                'SELECT a.*, u.username FROM ' . Database::identifier('attachments') . ' a'
                . ' LEFT JOIN ' . Database::identifier('users') . ' u ON u.'
                . Database::identifier('id') . ' = a.' . Database::identifier('user_id')
                . ' WHERE a.' . Database::identifier('id') . ' IN (' . $in . ')'
                . ' AND a.' . Database::identifier('deleted_at') . ' IS NULL'
                . ' ORDER BY a.' . Database::identifier('id') . ' ASC',
                array_values($allIds)
            );

            foreach ($rows as $row) {
                $files[(int)$row['id']] = $row;
            }
        }

        foreach ($notices as &$notice) {
            $list = [];

            foreach (self::attachmentIds($notice) as $id) {
                if (isset($files[$id])) {
                    $list[] = $files[$id];
                }
            }

            $notice['attachments'] = $list;
        }

        unset($notice);

        return $notices;
    }

    /**
     * 附件 → 公告 的反查表
     *
     * notices.attachment_ids 是公告与附件之间**唯一的事实来源**：
     * attachments 表只有 thread_id / post_id（那是帖子的概念），公告不属于帖子，
     * 所以附件在后台附件页里会显示成「未绑定」。这里主动扫一遍公告得出归属关系，
     * 让后台附件页的「关联内容」列能显示「公告《标题》」。
     *
     * 单次请求内缓存一次（公告数量有限，扫一遍很便宜）。
     *
     * @return array<int, array{id:int, title:string, enabled:int}>
     */
    public static function attachmentOwners(): array
    {
        static $map = null;

        if ($map !== null) {
            return $map;
        }

        $map = [];

        foreach (self::forManage() as $notice) {
            foreach (self::attachmentIds($notice) as $attId) {
                // 同一附件被多条公告引用时，保留最先遇到的那条（列表已按 sort 排序）
                if (!isset($map[$attId])) {
                    $map[$attId] = [
                        'id'      => (int)($notice['id'] ?? 0),
                        'title'   => (string)($notice['title'] ?? ''),
                        'enabled' => (int)($notice['enabled'] ?? 0),
                    ];
                }
            }
        }

        return $map;
    }

    /**
     * 管理视图用的列表：连同「未公开」的公告一起返回
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forManage(): array
    {
        self::ensureTable();

        return Database::select(
            'SELECT * FROM ' . Database::identifier(static::$table)
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL'
            . ' ORDER BY ' . Database::identifier('sort') . ' ASC, '
            . Database::identifier('id') . ' DESC'
        );
    }

    /**
     * 新建公告
     *
     * @param array<string, mixed> $input
     * @return int 新公告 ID
     */
    public static function createNotice(array $input): int
    {
        self::ensureTable();

        return static::create([
            'name'           => mb_substr(trim((string)($input['name'] ?? '')), 0, 100),
            'title'          => mb_substr(trim((string)($input['title'] ?? '')), 0, 200),
            'body'           => (string)($input['body'] ?? ''),
            'attachment_ids' => self::normalizeIds($input['attachment_ids'] ?? ''),
            'enabled'        => !empty($input['enabled']) ? 1 : 0,
            'sort'           => max(0, (int)($input['sort'] ?? self::DEFAULT_SORT)),
        ]);
    }

    /**
     * 更新公告
     *
     * @param array<string, mixed> $input
     */
    public static function updateNotice(int $id, array $input): void
    {
        self::ensureTable();

        static::updateById($id, [
            'name'           => mb_substr(trim((string)($input['name'] ?? '')), 0, 100),
            'title'          => mb_substr(trim((string)($input['title'] ?? '')), 0, 200),
            'body'           => (string)($input['body'] ?? ''),
            'attachment_ids' => self::normalizeIds($input['attachment_ids'] ?? ''),
            'enabled'        => !empty($input['enabled']) ? 1 : 0,
            'sort'           => max(0, (int)($input['sort'] ?? self::DEFAULT_SORT)),
        ]);
    }

    /**
     * 附件 ID 列表规范化
     *
     * 接受数组（表单的 attachments[]）或逗号分隔字符串，统一成
     * 「去重、升序、逗号分隔」的字符串存库，便于附件管理页做反向查询。
     *
     * @param mixed $value
     */
    public static function normalizeIds(mixed $value): string
    {
        $ids = [];

        foreach (is_array($value) ? $value : explode(',', (string)$value) as $item) {
            $id = (int)$item;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        sort($ids);

        return implode(',', $ids);
    }

    /**
     * 解析公告的附件 ID
     *
     * @param array<string, mixed> $notice
     * @return array<int, int>
     */
    public static function attachmentIds(array $notice): array
    {
        $raw = trim((string)($notice['attachment_ids'] ?? ''));

        if ($raw === '') {
            return [];
        }

        $ids = [];

        foreach (explode(',', $raw) as $item) {
            $id = (int)$item;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * 附件引用统计（附件管理页用）
     *
     * 「已引用 / 未被公告引用」都基于 attachment_ids 这个显式引用关系，
     * 不去正文里猜哈希 —— 本项目的附件本来就是靠 ID 绑定的。
     *
     * @return array{
     *     total: int,
     *     referenced: array<int, int>,
     *     used_total: int,
     *     unused_total: int
     * }
     */
    public static function attachmentStats(): array
    {
        self::ensureTable();

        $referenced = [];
        $usedTotal = 0;

        foreach (self::forManage() as $notice) {
            foreach (self::attachmentIds($notice) as $id) {
                $referenced[$id] = $id;
                $usedTotal++;
            }
        }

        $total = (int)Database::query(
            'SELECT COUNT(*) FROM ' . Database::identifier('attachments')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL'
        )->fetchColumn();

        $usedCount = 0;

        if ($referenced !== []) {
            $in = Database::placeholders(count($referenced));

            $usedCount = (int)Database::query(
                'SELECT COUNT(*) FROM ' . Database::identifier('attachments')
                . ' WHERE ' . Database::identifier('id') . ' IN (' . $in . ')'
                . ' AND ' . Database::identifier('deleted_at') . ' IS NULL',
                array_values($referenced)
            )->fetchColumn();
        }

        return [
            'total'        => $total,
            'referenced'   => $referenced,
            'used_total'   => $usedCount,
            'unused_total' => max(0, $total - $usedCount),
        ];
    }

    /**
     * 公告主列表（含各自引用的附件，供附件管理页展示「被哪些公告引用」）
     *
     * @return array<int, array{notice: array<string, mixed>, attachments: array<int, array<string, mixed>>}>
     */
    public static function withAttachments(): array
    {
        self::ensureTable();

        $rows = [];

        foreach (self::forManage() as $notice) {
            $ids = self::attachmentIds($notice);
            $files = [];

            if ($ids !== []) {
                $in = Database::placeholders(count($ids));

                $files = Database::select(
                    'SELECT a.*, u.username FROM ' . Database::identifier('attachments') . ' a'
                    . ' LEFT JOIN ' . Database::identifier('users') . ' u ON u.'
                    . Database::identifier('id') . ' = a.' . Database::identifier('user_id')
                    . ' WHERE a.' . Database::identifier('id') . ' IN (' . $in . ')'
                    . ' AND a.' . Database::identifier('deleted_at') . ' IS NULL'
                    . ' ORDER BY a.' . Database::identifier('id') . ' ASC',
                    array_values($ids)
                );

                /*
                 * 引用了但已不存在的附件（记录被删除/清理）：补一条占位行，
                 * 让管理页能看出「这条公告挂着一个死引用」，而不是静默少一行。
                 */
                $found = [];
                foreach ($files as $file) {
                    $found[(int)$file['id']] = true;
                }
                foreach ($ids as $id) {
                    if (!isset($found[$id])) {
                        $files[] = [
                            'id'      => $id,
                            'name'    => '附件 #' . $id . '（记录已不存在）',
                            'missing' => true,
                        ];
                    }
                }
            }

            $rows[] = ['notice' => $notice, 'attachments' => $files];
        }

        return $rows;
    }

    /**
     * 未公开的公告条数（前台只展示已公开的，管理入口用这个提示还有多少草稿）
     */
    public static function hiddenCount(): int
    {
        self::ensureTable();

        return static::countWhere(['enabled' => 0]);
    }

    /**
     * 前台搜索：标题或正文命中关键词的已发布公告（最多 $limit 条，不分页）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function search(string $keyword, int $limit = 5): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }

        self::ensureTable();

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
        $like    = '%' . $escaped . '%';
        $likeOp  = Database::likeOperator();

        return self::withFiles(Database::select(
            'SELECT * FROM ' . Database::identifier(static::$table)
            . ' WHERE ' . Database::identifier('enabled') . ' = 1'
            . ' AND ' . Database::identifier('deleted_at') . ' IS NULL'
            . ' AND (' . Database::identifier('title') . ' ' . $likeOp . ' ?' . Database::likeEscapeClause()
            . ' OR ' . Database::identifier('body') . ' ' . $likeOp . ' ?' . Database::likeEscapeClause() . ')'
            . ' ORDER BY ' . Database::identifier('updated_at') . ' DESC'
            . ' LIMIT ' . max(1, $limit),
            [$like, $like]
        ));
    }
}
