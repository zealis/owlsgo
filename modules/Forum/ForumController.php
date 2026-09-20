<?php
/**
 * 版块控制器：首页版块总览 + 版块内帖子列表
 */

declare(strict_types=1);

namespace Modules\Forum;

use Core\Controller;
use Core\Hook;
use Core\Paginator;
use Core\Permission;
use Core\Request;
use Core\Router;
use Modules\Thread\ThreadModel;

final class ForumController extends Controller
{
    /**
     * 首页：三个页签（新评论 / 新帖子 / 板块）+ 右侧栏
     *
     * @param array<string, string> $params
     */
    public function index(array $params): string
    {
        $user   = \Core\Auth::user();
        $forums = ForumModel::visible($user);

        // 插件可增删版块列表
        $forums = (array)Hook::filter('forum_list', $forums, ['user' => $user]);

        /*
         * 「最后发表」按帖子表实时补齐，不信任 forums 上的 last_thread_* 冗余列 ——
         * 那三列曾经出现「文字与链接指向不同帖子」「指向已删除帖子」的脏数据，
         * 症状就是点「最后发表」跳到了别的帖子（详见 ForumModel::withLastThread()）。
         */
        $forums = ForumModel::withLastThread($forums);

        $tree = ForumModel::tree($forums);

        /*
         * 首页左栏四个页签的数据（每页 20 条，带系统统一翻页）：
         *  - 新评论：按「最后评论时间」倒序的**帖子**（不是评论列表 —— 谁刚被评论，帖子排前面）
         *  - 新帖子：按发布时间倒序的帖子
         *  - 板块：上面的版块树
         *  - 推荐：全站标记了「推荐」的帖子
         * page 参数只作用于**当前激活**的页签（?tab=N），其余页签始终取第一页；
         * 非激活页签不渲染翻页条，避免隐藏面板里出现误导性的分页链接。
         * 各列表都不带正文摘要（withExcerpt = false）：首页要的是一眼扫过的条目。
         */
        /*
         * 每页条数走站点统一配置（config app.per_page），与版块页 / 用户主页 / 通知中心
         * 是同一个来源 —— 原来这里写死 20，改配置对首页不生效。
         */
        $perPage   = max(1, (int)config('app.per_page', 20));
        $activeTab = max(1, min(4, (int)Request::int('tab', 1)));
        $page      = $this->currentPage();

        /*
         * 三个列表统一走分页方法（都带置顶优先排序）：激活页签用 ?page，其余取第 1 页。
         * ⚠️ 不要图省事用非分页的 latest()/newest() 填充隐藏面板 —— 它们的排序
         * 与分页版不一致，会出现「切换页签后置顶消失」这类不一致。
         */

        $latestResult = ThreadModel::paginateLatest($activeTab === 1 ? $page : 1, $perPage);
        $newestResult = ThreadModel::paginateNewest($activeTab === 2 ? $page : 1, $perPage);
        $recResult    = ThreadModel::paginateRecommended($activeTab === 3 ? $page : 1, $perPage);

        $newComments = ThreadModel::decorate($latestResult['items'], false);
        $newThreads  = ThreadModel::decorate($newestResult['items'], false);
        $recommended = ThreadModel::decorate($recResult['items'], false);

        $activePage = match ($activeTab) {
            2       => $newestResult,
            3       => $recResult,
            default => $latestResult,
        };

        /*
         * 翻页条只给「有分页概念」的三个列表（新评论 / 新帖子 / 推荐）。
         * 「板块」是版块总览，没有分页，不要拿新评论的结果去渲染 ——
         * 那样点第 2 页会翻到一组跟眼前内容无关的页码。
         */
        $homePaginator = $activeTab >= 1 && $activeTab <= 3
            ? Paginator::render($activePage, '/', ['tab' => $activeTab])
            : '';

        // 合并同一作者的连续记录，减少后续查询
        $hot = ThreadModel::decorate(ThreadModel::hot(6), false);

        return $this->view('forum/index', [
            'pageTitle'     => (string)setting('site_name', 'owlsgo'),
            'tree'          => $tree,
            'newComments'   => $newComments,
            'newThreads'    => $newThreads,
            'recommended'   => $recommended,
            'hot'           => $hot,
            'activeTab'     => $activeTab,
            'homePaginator' => $homePaginator,
            'siteNotice'    => (string)setting('site_notice', ''),
            'canPost'       => Permission::allows($user, 'thread.create'),
        ]);
    }

    /**
     * 版块详情：帖子列表
     *
     * @param array<string, string> $params
     */
    public function show(array $params): string
    {
        $forumId = (int)($params['id'] ?? 0);
        $forum   = ForumModel::findOrFail($forumId);

        if ((int)$forum['status'] !== 1 && !\Core\Auth::can('admin.forum')) {
            \Core\App::abort(404, '该版块暂未开放。');
        }

        $user = \Core\Auth::user();

        // 版块级权限：不可浏览则直接 403
        if (!Permission::canViewForum($user, $forum)) {
            if ($user === null) {
                \Core\Auth::requireLogin();
            }
            \Core\App::abort(403, '你没有权限浏览该版块。');
        }

        $page = isset($params['page']) ? Paginator::page((int)$params['page']) : $this->currentPage();

        $filters = [
            'essence'   => Request::bool('essence'),
            'keyword'   => Request::string('q', '', 60),
            'author_id' => Request::int('author', 0),
        ];

        $result = ThreadModel::paginateInForum(
            $forumId,
            $page,
            (int)config('app.per_page', 20),
            $filters
        );

        $result['items'] = ThreadModel::decorate($result['items']);
        $result['items'] = (array)Hook::filter('thread_list', $result['items'], ['forum' => $forum]);

        // 标记当前用户已收藏的帖子，避免模板里逐个查库
        $favorited = \Modules\Thread\FavoriteModel::favoritedMap(
            \Core\Auth::id(),
            array_map(static fn (array $t): int => (int)$t['id'], $result['items'])
        );

        return $this->view('forum/show', [
            'pageTitle'   => (string)$forum['name'] . ' - ' . (string)setting('site_name'),
            'forum'        => $forum,
            'result'       => $result,
            'filters'      => $filters,
            'favorited'    => $favorited,
            'pagination'   => Paginator::render($result, '/f/' . $forumId, array_filter([
                'q'       => $filters['keyword'],
                'essence' => $filters['essence'] ? 1 : '',
                // 作者筛选也要带上：否则按作者筛完翻到第 2 页就变回「全部帖子」
                'author'  => $filters['author_id'] > 0 ? $filters['author_id'] : '',
            ])),
            'canCreate'    => Permission::canCreateThread($user, $forum),
            'canModerate'  => Permission::allows($user, 'thread.essence', $forum),
            'subForums'    => array_values(array_filter(
                ForumModel::all(),
                static fn (array $f): bool => (int)$f['parent_id'] === $forumId && (int)$f['status'] === 1
            )),
            'backUrl'      => Router::url('/'),
        ]);
    }
}
