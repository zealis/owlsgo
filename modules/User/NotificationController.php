<?php
/**
 * 通知控制器：通知列表与标记已读
 *
 * 通知只对「接收者本人」可见，任何情况下都不允许越权读取他人的通知。
 */

declare(strict_types=1);

namespace Modules\User;

use Core\Controller;
use Core\Paginator;
use Core\Request;
use Core\Router;
use Modules\Notice\NoticeModel;

final class NotificationController extends Controller
{
    /**
     * 通知列表
     *
     * @param array<string, string> $params
     */
    public function index(array $params): string
    {
        $user = $this->requireLogin();
        $page = $this->currentPage();

        /*
         * 每页条数与首页 / 版块页 / 用户主页用同一套配置（config app.per_page），
         * 不再各写各的数字 —— 改一处就能统一调整全站列表长度。
         */
        $perPage = (int)config('app.per_page', 20);

        $result = NotificationModel::forUser((int)$user['id'], $page, $perPage);
        $result['items'] = $this->decorate($result['items']);

        /*
         * 公告区同样按这个条数分页：公告本来就不多，条数没超限时 Paginator
         * 不会输出分页条，界面跟不分页时一模一样；超限后才出现翻页。
         * 页码参数用 npage —— 与「我的通知」的 page 分开，互不干扰，
         * 两个分页各自带上对方的页码，翻其中一个不会把另一个重置回第一页。
         */
        $noticePage   = Paginator::page(Request::int('npage', 1));
        $noticeResult = NoticeModel::paginatePublished($noticePage, $perPage);

        return $this->view('user/notifications', [
            'pageTitle'         => '通知 - ' . (string)setting('site_name'),
            'result'            => $result,
            'notices'           => $noticeResult['items'],
            'noticeTotal'       => (int)$noticeResult['total'],
            'noticePagination'  => Paginator::render(
                $noticeResult,
                '/notifications',
                $page > 1 ? ['page' => $page] : [],
                'npage'          // 公告自己的页码参数，避免与「我的通知」的 ?page 打架
            ),
            'unread'            => NotificationModel::unreadCount((int)$user['id']),
            'pagination'        => Paginator::render(
                $result,
                '/notifications',
                $noticePage > 1 ? ['npage' => $noticePage] : []
            ),
        ], 'layouts/main');
    }

    /**
     * 标记已读
     *
     * 传 id 表示标记单条，不传则全部标记已读。
     *
     * @param array<string, string> $params
     */
    public function markRead(array $params): never
    {
        $user = $this->requireLogin();
        $id   = Request::int('id', 0);

        if ($id > 0) {
            $row = NotificationModel::find($id);

            // 只能操作自己的通知，越权时静默忽略而不泄露「该通知是否存在」
            if ($row !== null && (int)$row['recipient_id'] === (int)$user['id']) {
                NotificationModel::updateById($id, ['is_read' => 1]);
                NotificationModel::flushCount();
            }
        } else {
            NotificationModel::markAllRead((int)$user['id']);
        }

        $unread = NotificationModel::unreadCount((int)$user['id']);

        if (Request::wantsJson()) {
            $this->json([
                'ok'      => true,
                'message' => $id > 0 ? '已标记为已读。' : '已全部标记为已读。',
                'unread'  => $unread,
            ]);
        }

        $this->redirectWith(Router::url('/notifications'), '通知已标记为已读。');
    }

    /* ------------------------------------------------------------------ */
    /*  内部辅助                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * 补齐发送者信息与跳转链接（批量查询，避免 N+1）
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function decorate(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $senderIds = array_map(static fn (array $n): int => (int)$n['sender_id'], $items);
        $senders   = UserModel::mapByIds($senderIds);

        $placeholder = [
            'id'       => 0,
            'username' => '系统',
            'avatar'   => '',
        ];

        foreach ($items as &$item) {
            $sender = $senders[(int)$item['sender_id']] ?? $placeholder;
            $kind   = (string)$item['kind'];

            $item['sender']     = $sender;
            $item['kind_name']  = NotificationModel::KINDS[$kind] ?? '通知';
            $item['link']       = NotificationModel::link($item);
            $item['created_at'] = (int)$item['created_at'];
        }
        unset($item);

        return $items;
    }
}
