<?php
/**
 * 版块控制器：首页版块总览 + 版块内主题列表
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
     * 首页：版块分组 + 站点统计 + 最新主题
     *
     * @param array<string, string> $params
     */
    public function index(array $params): string
    {
        $user   = \Core\Auth::user();
        $forums = ForumModel::visible($user);

        // 插件可增删版块列表
        $forums = (array)Hook::filter('forum_list', $forums, ['user' => $user]);

        $tree = ForumModel::tree($forums);

        // 首页统计：一次查三张表的计数
        $stats = [
            'forums'  => count($forums),
            'threads' => ThreadModel::totalCount(),
            'posts'   => \Modules\Post\PostModel::totalCount(),
            'users'   => \Modules\User\UserModel::totalCount(),
        ];

        $latest = ThreadModel::latest(8);
        $latest = ThreadModel::decorate($latest, false);

        // 合并同一作者的连续记录，减少后续查询
        $hot = ThreadModel::decorate(ThreadModel::hot(6), false);

        return $this->view('forum/index', [
            'pageTitle'   => (string)setting('site_name', 'owlsgo'),
            'tree'        => $tree,
            'stats'       => $stats,
            'latest'      => $latest,
            'hot'         => $hot,
            'siteNotice'  => (string)setting('site_notice', ''),
            'canPost'     => Permission::allows($user, 'thread.create'),
        ]);
    }

    /**
     * 版块详情：主题列表
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

        // 标记当前用户已收藏的主题，避免模板里逐个查库
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
