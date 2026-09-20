<?php
/**
 * 附件上传接口
 *
 * 设计说明：
 *  - 上传与「绑定」分离：本接口只负责把文件落盘并生成一条 attachments 记录（post_id = 0），
 *    真正的绑定由发帖/评论时提交的 attachments[] ID 列表完成（见 AttachmentModel::bindToPost）。
 *    这样即使用户上传后放弃发帖，也不会污染任何帖子。
 *  - 绑定阶段会校验「必须是本人上传且尚未绑定」，因此这里不需要预先知道版块/帖子。
 *  - 复用 Core\Upload 的 MIME 探测、扩展名交叉校验与随机重命名，不另开任何安全口子。
 */

declare(strict_types=1);

namespace Modules\User;

use Core\Auth;
use Core\Controller;
use Core\Router;
use Core\Settings;
use Core\Upload;

final class UploadController extends Controller
{
    /**
     * 接收单个文件（表单字段名固定为 file）
     *
     * @param array<string, string> $params
     */
    public function store(array $params): never
    {
        $user = $this->requireLogin();

        if (!Settings::bool('upload_enabled', true)) {
            $this->fail('站点已关闭附件上传功能。', 403);
        }

        if (!Auth::can('attachment.upload')) {
            $this->fail('你没有上传附件的权限。', 403);
        }

        /*
         * 双层限流：
         *  - upload_burst：每分钟最多 10 次 —— 限制「瞬时速度」，防止一次拖几十个文件把带宽打满；
         *  - upload：每 10 分钟最多 20 次 —— 限制总量配额。
         * 触发任意一层都会收到 429 + Retry-After，前端把提示显示在对应附件行上。
         */
        $this->throttle('upload_burst', 'upload_burst:' . (int)$user['id']);
        $this->throttle('upload', 'upload:' . (int)$user['id']);

        $file = $_FILES['file'] ?? null;

        if (!is_array($file)) {
            $this->fail('请选择要上传的文件。');
        }

        try {
            $stored = Upload::store($file, false);
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage());
        }

        /*
         * 同文件去重：按 sha256 查找未删除的同内容附件。
         * 命中时不落新文件（刚写入的重复文件立即删除），直接复用已有记录 ——
         * 一个完全相同的文件全站只存一份。
         * 前端收到 duplicate 标记后仍会把附件加入列表，只是提示语不同。
         */
        $duplicate = AttachmentModel::findByHash((string)($stored['hash'] ?? ''));

        if ($duplicate !== null) {
            Upload::remove((string)$stored['path']);

            $this->json([
                'ok'        => true,
                'duplicate' => true,
                'message'   => '该文件已存在，已复用之前的附件，不再重复存储。',
                'id'        => (int)$duplicate['id'],
                'name'      => (string)$duplicate['name'],
                'is_image'  => !empty($duplicate['is_image']),
                'size'      => (int)$duplicate['size'],
                'size_text' => format_size((int)$duplicate['size']),
                'url'       => Router::url('/attachment/' . (int)$duplicate['id']),
            ]);
        }

        /*
         * 用户组附件空间配额（单位 MB 存在 usergroups 上，0 = 不限制）。
         *
         * 位置有讲究：
         *  - 放在**去重之后**：命中同内容文件时既不落新文件也不产生新记录，
         *    自然不该扣配额，否则用户会被一个「其实不占空间」的上传卡住；
         *  - 用 `$stored['size']`（Upload 探测出的真实大小）而不是 `$_FILES['size']`，
         *    前端报上来的大小不可信。
         * 超限时把已经落盘的文件删掉再报错，避免留下无人引用的孤儿文件。
         */
        $quota = AttachmentModel::quotaBytes($user);

        if ($quota > 0) {
            $used = AttachmentModel::usedBytes((int)$user['id']);
            $size = (int)$stored['size'];

            if ($used + $size > $quota) {
                Upload::remove((string)$stored['path']);

                $this->fail(
                    '附件空间不足：已用 ' . format_size($used) . ' / 上限 ' . format_size($quota)
                    . '，本次文件 ' . format_size($size) . '。'
                    . '请联系管理员调整用户组配额，或先清理已有的附件。',
                    413
                );
            }
        }

        $attachmentId = AttachmentModel::record((int)$user['id'], $stored);

        if ($attachmentId <= 0) {
            // 数据库写入失败时清理已落盘的文件，避免产生孤儿文件
            Upload::remove((string)$stored['path']);
            $this->fail('附件保存失败，请稍后重试。', 500);
        }

        $this->json([
            'ok'        => true,
            'message'   => '附件已上传。',
            'id'        => $attachmentId,
            'name'      => (string)$stored['name'],
            'is_image'  => (bool)$stored['is_image'],
            'size'      => (int)$stored['size'],
            'size_text' => format_size((int)$stored['size']),
            'url'       => Router::url('/attachment/' . $attachmentId),
        ]);
    }
}
