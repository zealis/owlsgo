<?php
/**
 * 侧边栏数据提供者
 *
 * 前台除「个人管理页面」外的所有页面共用同一个右侧栏（partials/sidebar.php）：
 * 发表新帖子按钮 + 最新帖子 + 热门帖子。首页本来就有这三个数据的查询，
 * 其余页面（版块页 / 帖子详情 / 搜索 / 发帖 / 编辑）没有 —— 由这里统一取。
 *
 * 设计要点：
 *  - **进程内缓存**：同一次请求只查一次库，partial 被多次渲染也只查一次；
 *  - 口径与首页完全一致：最新帖子 = `paginateLatest(1, 8)`（按最后评论时间倒序、置顶优先），
 *    热门帖子 = `hot(6)`；条数写死在这里，改首页时同步改这里，避免两处不一致；
 *  - 首页已经把数据查好了，直接通过 `$view('partials/sidebar', [...])` 传进来，
 *    不会触发本类（见 partials/sidebar.php 的取值顺序）。
 */

declare(strict_types=1);

namespace Modules\Forum;

use Core\Auth;
use Core\Permission;
use Modules\Thread\ThreadModel;

final class Sidebar
{
    /** 最新帖子条数（与首页右栏一致） */
    private const LATEST_LIMIT = 8;

    /** 热门帖子条数（与首页右栏一致） */
    private const HOT_LIMIT = 6;

    /** @var array{latest: array<int, array<string, mixed>>, hot: array<int, array<string, mixed>>, canPost: bool}|null */
    private static ?array $cache = null;

    /**
     * 取侧栏数据（进程内缓存）
     *
     * @return array{latest: array<int, array<string, mixed>>, hot: array<int, array<string, mixed>>, canPost: bool}
     */
    public static function data(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $latestResult = ThreadModel::paginateLatest(1, self::LATEST_LIMIT);
        $latestItems  = is_array($latestResult['items'] ?? null) ? $latestResult['items'] : [];

        return self::$cache = [
            /* 列表不带正文摘要：右栏要的是一眼扫过的条目 */
            'latest'  => ThreadModel::decorate($latestItems, false),
            'hot'     => ThreadModel::decorate(ThreadModel::hot(self::HOT_LIMIT), false),
            'canPost' => Permission::allows(Auth::user(), 'thread.create'),
        ];
    }
}
