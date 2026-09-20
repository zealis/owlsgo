<?php
/**
 * 后台：附件管理
 *
 * 提供全站附件的检索、占用统计与删除。
 * 删除会同时清理磁盘文件（AttachmentModel::destroy 内部调用 Upload::remove），
 * 因此这里不需要也不应该再直接触碰文件系统。
 */

declare(strict_types=1);

namespace Modules\Admin;

use Core\App;
use Core\Paginator;
use Core\Request;
use Core\Router;
use Modules\Notice\NoticeModel;
use Modules\User\AttachmentModel;
use Modules\User\UserModel;

final class AttachmentController extends AdminBaseController
{
    /**
     * 附件列表
     *
     * @param array<string, string> $params
     */
    public function index(array $params): string
    {
        $keyword = Request::string('q', '', 60);
        $page    = $this->currentPage();

        $result = AttachmentModel::paginateAll($keyword, $page, 30);

        // 批量补齐上传者，避免逐行查库
        $uploaderIds = array_map(static fn (array $a): int => (int)$a['user_id'], $result['items']);
        $uploaders   = UserModel::mapByIds($uploaderIds);

        /*
         * 附件归属：帖子看 thread_id/post_id，公告则反过来 —— 公告与附件的关系记在
         * notices.attachment_ids 里（附件表没有公告概念），所以向公告反查一次。
         */
        $noticeOwners = NoticeModel::attachmentOwners();

        foreach ($result['items'] as &$item) {
            $uploader = $uploaders[(int)$item['user_id']] ?? null;

            $item['uploader']  = $uploader;
            $item['size_text'] = format_size((int)$item['size']);
            $item['notice']    = $noticeOwners[(int)$item['id']] ?? null;
        }
        unset($item);

        $stats = AttachmentModel::stats();

        return $this->adminView('admin/attachments', [
            'pageTitle'  => '附件管理 - ' . (string)setting('site_name'),
            'adminTitle' => '附件管理',
            'result'     => $result,
            'keyword'    => $keyword,
            'stats'      => [
                'total' => (int)$stats['total'],
                'bytes' => format_size((int)$stats['bytes']),
            ],
            'pagination' => Paginator::render($result, '/admin/attachments', array_filter(['q' => $keyword])),
        ]);
    }

    /**
     * 批量操作：删除附件
     *
     * 逐条走 AttachmentModel::destroy（磁盘文件 + 记录成对处理），
     * 也就是说**没有**「回收站」这一步 —— 附件删除本来就是不可恢复的，
     * 界面上会明确提示这一点。
     *
     * @param array<string, string> $params
     */
    public function bulk(array $params): never
    {
        $ids    = Request::intArray('items');
        $action = (string)Request::post('action', '');
        $back   = Request::referer() !== '' ? Request::referer() : Router::url('/admin/attachments');

        if ($ids === []) {
            $this->bulkFail('请先勾选要删除的附件。', $back);
        }

        if ($action !== 'delete') {
            $this->bulkFail('未知的批量操作。', $back);
        }

        $done = AttachmentModel::destroyMany($ids);

        $this->audit('attachment.delete', 'bulk', '批量删除 ' . $done . ' 个附件');

        $message = '已删除 ' . $done . ' 个附件（文件已从磁盘移除，不可恢复）。';

        if (Request::wantsJson()) {
            $this->json(['ok' => true, 'message' => $message, 'redirect' => $back]);
        }

        $this->redirectWith($back, $message);
    }

    /**
     * 删除附件
     *
     * @param array<string, string> $params
     */
    public function destroy(array $params): never
    {
        $id         = (int)($params['id'] ?? 0);
        $attachment = AttachmentModel::find($id);

        if ($attachment === null) {
            App::abort(404, '附件不存在或已被删除。');
        }

        $back = Request::referer() !== '' ? Request::referer() : Router::url('/admin/attachments');

        AttachmentModel::destroy($id);

        $this->audit(
            'attachment.delete',
            'attachment:' . $id,
            '删除附件：' . (string)$attachment['name'] . '（' . format_size((int)$attachment['size']) . '）'
        );

        $message = '附件已删除。';

        if (Request::wantsJson()) {
            $this->json(['ok' => true, 'message' => $message, 'redirect' => $back]);
        }

        $this->redirectWith($back, $message);
    }
}
