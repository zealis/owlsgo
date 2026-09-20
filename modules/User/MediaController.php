<?php
/**
 * 媒体资源控制器：内置 SVG 头像、已上传文件、附件下载
 *
 * 安全模型（这是全站唯一直接回吐文件内容的地方，必须谨慎）：
 *  1. storage/ 位于 Web 根之外，任何文件都只能经由本控制器读取
 *  2. 路径一律通过 Upload::absolutePath() 解析，硬阻断目录穿越
 *  3. 响应头固定 nosniff，并且只对「真实图片」内联展示
 *  4. SVG 与所有非图片类型一律以 application/octet-stream 强制下载，
 *     从根本上消除「上传文件被当作页面脚本执行」的可能
 *  5. 附件下载前按所属帖子/版块做可见性校验，防止猜到 ID 就下载
 */

declare(strict_types=1);

namespace Modules\User;

use Core\App;
use Core\Auth;
use Core\Avatar;
use Core\Controller;
use Core\Database;
use Core\Permission;
use Core\Request;
use Core\Response;
use Core\Upload;
use Modules\Post\PostModel;

final class MediaController extends Controller
{
    /**
     * 内置 SVG 头像
     *
     * @param array<string, string> $params
     */
    public function avatar(array $params): never
    {
        $seed = (string)($params['seed'] ?? '');

        // 种子只允许字母数字与少量符号，避免被用作注入载体
        if ($seed === '' || preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $seed) !== 1) {
            $seed = 'guest';
        }

        $size  = Request::int('s', 96);
        $style = Request::string('style', '', 20);

        $svg = Avatar::svg($seed, $size, $style);

        Response::header('Content-Type', 'image/svg+xml; charset=UTF-8');
        // 头像 SVG 不含任何外部引用，直接锁死资源加载与脚本执行
        Response::header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        Response::header('X-Content-Type-Options', 'nosniff');
        Response::header('Cache-Control', 'public, max-age=604800, immutable');

        Response::raw($svg);
    }

    /**
     * 读取 storage 下的公开文件（仅限头像）
     *
     * 附件一律走 /attachment/{id} 的权限校验，本路由不提供任何附件访问。
     * 历史上头像与附件混存在 uploads/ 下，对仍被 users.avatar 引用的
     * 旧路径保持兼容（头像本就是全员可见的资源），其余路径一律 403。
     *
     * @param array<string, string> $params
     */
    public function media(array $params): never
    {
        $path = (string)($params['path'] ?? '');

        if (!$this->isPublicMedia($path)) {
            App::abort(403, '该资源不对外公开。');
        }

        $absolute = Upload::absolutePath($path);

        if ($absolute === null || !is_file($absolute)) {
            App::abort(404, '资源不存在。');
        }

        $mime = $this->mimeOf($absolute);
        $ext  = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));

        // SVG 可能内嵌 <script>，即使作为 <img> 引用也可能有风险，一律强制下载
        $inline = str_starts_with($mime, 'image/') && !in_array($ext, ['svg', 'svgz'], true);

        if (!$inline) {
            $this->serve($absolute, 'application/octet-stream', true, basename($absolute));
        }

        Response::header('Content-Security-Policy', "default-src 'none'; sandbox");

        $this->serve($absolute, $mime, false, basename($absolute));
    }

    /**
     * 附件下载
     *
     * @param array<string, string> $params
     */
    public function attachment(array $params): never
    {
        $id         = (int)($params['id'] ?? 0);
        $attachment = AttachmentModel::find($id);

        if ($attachment === null || (int)$attachment['status'] !== 1) {
            App::abort(404, '附件不存在或已被删除。');
        }

        // 可见性校验：附件跟随所属帖子/版块，避免凭 ID 盲猜下载私有内容
        $postId = (int)$attachment['post_id'];
        if ($postId > 0) {
            $context = PostModel::withContext($postId);

            if ($context === null) {
                App::abort(404, '附件所属内容已不存在。');
            }

            if (!Permission::canViewForum(Auth::user(), $context['forum'])) {
                App::abort(403, '你没有权限下载该附件。');
            }
        }

        /*
         * 附件权限：图片与普通文件一视同仁，都要求「下载附件」（游客组默认没有，
         * 可在用户组里授予）。
         *
         * 早先给图片开过特例，理由是「图片内嵌在正文里，拦了会满屏碎图」—— 这个理由
         * 并不成立：楼层与公告里的图片同样是附件列表里的一条链接（点开才请求本路由），
         * 正文里并没有 <img src="/attachment/...">。所以能看到帖子 ≠ 能取走文件。
         *
         * 能「管理附件」的人自然也允许下载，不必额外勾一项。
         */
        if (!Permission::allows(Auth::user(), 'attachment.download')
            && !Permission::allows(Auth::user(), 'attachment.manage')) {
            App::abort(403, '当前用户组没有查看或下载附件的权限，请先登录或联系管理员。');
        }

        $absolute = Upload::absolutePath((string)$attachment['path']);

        if ($absolute === null || !is_file($absolute)) {
            App::abort(404, '附件文件已丢失。');
        }

        // 下载计数使用原子自增，避免并发下的「读-改-写」丢计数
        AttachmentModel::increment($id, 'downloads', 1);

        $mime = strtolower((string)($attachment['mime'] ?? ''));
        if ($mime === '') {
            $mime = $this->mimeOf($absolute);
        }

        $name = (string)($attachment['name'] ?? basename($absolute));

        if ((int)$attachment['is_image'] === 1 && str_starts_with($mime, 'image/')) {
            Response::header('Content-Security-Policy', "default-src 'none'; sandbox");
            $this->serve($absolute, $mime, false, $name);
        }

        $this->serve($absolute, 'application/octet-stream', true, $name);
    }

    /* ------------------------------------------------------------------ */
    /*  内部实现                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * /media 是否允许服务该路径
     *
     * 白名单两层：
     *  1. avatars/ —— 新版头像目录（Upload::store 的 $isAvatar 分支写入）；
     *  2. uploads/ —— 仅当路径仍被某个用户的 avatar 字段引用时放行，
     *     兼容头像与附件混存时期的历史数据。附件路径不会出现在任何
     *     users.avatar 里，因此天然被挡在门外。
     */
    private function isPublicMedia(string $path): bool
    {
        $normalized = ltrim(str_replace('\\', '/', $path), '/');

        if (str_starts_with($normalized, 'avatars/')) {
            return true;
        }

        if (str_starts_with($normalized, 'uploads/')) {
            if (!App::isInstalled()) {
                return false;
            }

            return (int)Database::value(
                'SELECT COUNT(*) FROM ' . Database::identifier('users')
                . ' WHERE ' . Database::identifier('avatar') . ' = ?'
                . ' AND ' . Database::identifier('deleted_at') . ' IS NULL',
                [$normalized]
            ) > 0;
        }

        return false;
    }

    /**
     * 输出文件内容
     *
     * @param string $absolute 已在 storage 内的绝对路径（调用方必须已验证）
     * @param string $mime     响应 Content-Type
     * @param bool   $download 是否强制下载
     * @param string $filename 下载时展示的文件名
     */
    private function serve(string $absolute, string $mime, bool $download, string $filename): never
    {
        // 丢弃任何已有输出缓冲，防止二进制内容被污染
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $size = @filesize($absolute);
        $size = $size === false ? 0 : $size;

        Response::header('Content-Type', $mime);
        Response::header('Content-Length', (string)$size);
        Response::header('X-Content-Type-Options', 'nosniff');
        Response::header('Cache-Control', 'private, max-age=86400');

        if ($download) {
            Response::header(
                'Content-Disposition',
                'attachment; filename="' . $this->asciiFilename($filename) . '"; '
                . "filename*=UTF-8''" . rawurlencode($filename)
            );
        }

        Response::sendHeaders();

        if ($size > 0) {
            readfile($absolute);
        }

        exit;
    }

    /**
     * 读取真实 MIME（finfo 优先，退化为按扩展名映射）
     */
    private function mimeOf(string $absolute): string
    {
        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($absolute);

            if (is_string($mime) && $mime !== '') {
                return strtolower($mime);
            }
        }

        $extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            'pdf'         => 'application/pdf',
            'txt', 'md', 'log' => 'text/plain',
            'zip'         => 'application/zip',
            'gz'          => 'application/gzip',
            default       => 'application/octet-stream',
        };
    }

    /**
     * 生成 Content-Disposition 的 ASCII 回退文件名（防响应头注入）
     */
    private function asciiFilename(string $name): string
    {
        $name = str_replace(['\\', '/', '"', "\r", "\n", "\0"], '', $name);
        $name = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? $name;
        $name = trim($name);

        return $name === '' ? 'download' : mb_substr($name, 0, 120);
    }
}
