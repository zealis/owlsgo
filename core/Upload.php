<?php
/**
 * 文件上传处理
 *
 * 安全要点（缺一不可）：
 *  1. 校验 upload 错误码与大小上限
 *  2. 用 finfo_file() 读取真实 MIME（绝不信任 $_FILES['type'] 与扩展名）
 *  3. 扩展名白名单，且必须与 MIME 匹配
 *  4. 文件名使用随机串重命名，杜绝目录穿越与覆盖攻击
 *  5. 存入 storage/uploads/（位于 Web 根之外），只能通过受控路由读取
 *  6. 目录内放置 .htaccess 与 index.html 阻止直接执行
 */

declare(strict_types=1);

namespace Core;

use RuntimeException;

final class Upload
{
    /**
     * 源码 / 网页类文件可接受的真实 MIME
     *
     * finfo 对纯文本的判定并不稳定：同一个 .php 可能是 text/x-php、text/plain，
     * 也可能是 application/octet-stream。所以这里用 text/* 通配 + 兜底，
     * 只要不是压缩包、可执行文件那类二进制容器就放行。
     *
     * 放宽 MIME 并不会让上传口子变大：这些扩展名在 EXT_MAP 里第三列都是 true，
     * 落盘时会被改名（shell.php → shell.php1），服务器永远不把它当脚本执行。
     */
    private const CODE_MIMES = [
        'text/*',
        'application/x-httpd-php',
        'application/octet-stream',
        'application/x-empty',
    ];

    /**
     * 已知安全的扩展名 => [可接受的真实 MIME 列表, 是否为图片, 是否改名保存]
     *
     * 这是「允许上传的扩展名」设置的最后一道闸：后台配置里写了什么扩展名，
     * 都必须先在这里登记过才会被接受，而且 finfo 读出的真实 MIME 必须落在对应的
     * MIME 列表里 —— 把 .zip 改名成 .jpg 之类的伪装会在这里被挡下。
     *
     * 第三列为 true 的类型（源码、网页）落盘时扩展名会被改写成 xxx1：
     * 站长可以按需放开它们用于交换源码，而文件在服务器上不是可执行类型，
     * 即便上传目录某天被 Web 服务器直接命中，也只是下载一个文本文件。
     *
     * ⚠️ svg / svgz 一律不要登记：它是 XML、可以内嵌 <script>，浏览器按图片渲染时
     *    脚本会真的执行 —— 改名保存也拦不住（浏览器认的是内容而非扩展名），
     *    所以只能整类拒绝。后台设置里同样把它拉黑（见 AdminController::normalizeExtensions）。
     *
     * @var array<string, array{0: list<string>, 1: bool, 2: bool}>
     */
    private const EXT_MAP = [
        /* 图片 */
        'jpg'  => [['image/jpeg'], true, false],
        'png'  => [['image/png'], true, false],
        'gif'  => [['image/gif'], true, false],
        'webp' => [['image/webp'], true, false],
        'avif' => [['image/avif'], true, false],
        'bmp'  => [['image/bmp', 'image/x-ms-bmp'], true, false],
        'ico'  => [['image/vnd.microsoft.icon', 'image/x-icon'], true, false],

        /* 文档 / 压缩包 / 文本 */
        'zip'  => [['application/zip', 'application/x-zip-compressed'], false, false],
        'rar'  => [['application/x-rar-compressed', 'application/vnd.rar', 'application/x-rar'], false, false],
        '7z'   => [['application/x-7z-compressed'], false, false],
        'gz'   => [['application/gzip', 'application/x-gzip'], false, false],
        'txt'  => [['text/plain'], false, false],
        'md'   => [['text/markdown', 'text/plain'], false, false],
        'log'  => [['text/plain', 'text/x-log'], false, false],
        'pdf'  => [['application/pdf'], false, false],
        'bin'  => [['application/octet-stream'], false, false],

        /* 源码 / 网页：改名保存，详见上方说明 */
        'php'   => [self::CODE_MIMES, false, true],
        'php3'  => [self::CODE_MIMES, false, true],
        'php4'  => [self::CODE_MIMES, false, true],
        'php5'  => [self::CODE_MIMES, false, true],
        'php7'  => [self::CODE_MIMES, false, true],
        'php8'  => [self::CODE_MIMES, false, true],
        'phtml' => [self::CODE_MIMES, false, true],
        'pht'   => [self::CODE_MIMES, false, true],
        'phps'  => [self::CODE_MIMES, false, true],
        'phar'  => [self::CODE_MIMES, false, true],
        'html'  => [self::CODE_MIMES, false, true],
        'htm'   => [self::CODE_MIMES, false, true],
        'shtml' => [self::CODE_MIMES, false, true],
        'xhtml' => [self::CODE_MIMES, false, true],
        'asp'   => [self::CODE_MIMES, false, true],
        'aspx'  => [self::CODE_MIMES, false, true],
        'jsp'   => [self::CODE_MIMES, false, true],
        'cgi'   => [self::CODE_MIMES, false, true],
        'pl'    => [self::CODE_MIMES, false, true],
        'py'    => [self::CODE_MIMES, false, true],
        'rb'    => [self::CODE_MIMES, false, true],
        'sh'    => [self::CODE_MIMES, false, true],
        'bat'   => [self::CODE_MIMES, false, true],
        'cmd'   => [self::CODE_MIMES, false, true],
        'vbs'   => [self::CODE_MIMES, false, true],
    ];

    /** 头像只允许这几种图片扩展名 */
    private const AVATAR_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** 设置项为空时的兜底扩展名（同时用于新装默认值） */
    private const FALLBACK_EXT = 'jpg,jpeg,png,gif,webp,zip,rar,7z,txt,pdf,md';

    /**
     * 当前站点允许上传的扩展名
     *
     * 取自后台设置「允许上传的扩展名」；设置为空时回退到内置列表，
     * 避免一次误操作把上传功能彻底锁死。
     *
     * @return list<string>
     */
    private static function allowedExtensions(): array
    {
        $raw  = (string) setting('upload_allow_ext', self::FALLBACK_EXT);
        $list = [];

        foreach (preg_split('/[\s,，;；]+/u', $raw) ?: [] as $piece) {
            $ext = strtolower(ltrim(trim($piece), '.'));

            if ($ext !== '') {
                $list[$ext] = true;
            }
        }

        return $list === [] ? explode(',', self::FALLBACK_EXT) : array_keys($list);
    }

    /**
     * 处理一个上传文件
     *
     * @param array<string, mixed> $file   $_FILES 中的单个条目
     * @param bool                 $isAvatar 是否为头像上传（仅允许图片、尺寸更小）
     * @return array{name:string,path:string,mime:string,size:int,hash:string,is_image:bool,absolute:string}
     *
     * @throws RuntimeException 校验失败时抛出，消息可直接展示给用户
     */
    public static function store(array $file, bool $isAvatar = false): array
    {
        if (!(bool)config('app.upload.enabled', true)) {
            throw new RuntimeException('站点已关闭附件上传功能。');
        }

        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::errorMessage($error));
        }

        $tmpPath  = (string)($file['tmp_name'] ?? '');
        $original = (string)($file['name'] ?? 'file');

        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            // php -S 等场景下 is_uploaded_file 可能不成立，退化为普通文件校验
            if ($tmpPath === '' || !is_file($tmpPath)) {
                throw new RuntimeException('上传失败：临时文件不可读。');
            }
        }

        $maxSize = $isAvatar
            ? (int)config('app.upload.avatar_size', 2097152)
            : (int)config('app.upload.max_size', 4194304);

        $size = (int)(@filesize($tmpPath) ?: 0);
        if ($size <= 0) {
            throw new RuntimeException('上传失败：文件内容为空。');
        }
        if ($size > $maxSize) {
            throw new RuntimeException('文件体积超出限制（最大 ' . format_size($maxSize) . '）。');
        }

        /*
         * 站点附件总空间配额（后台「附件总空间上限」，0 = 不限）。
         * 用数据库里的 SUM(size) 而不是扫磁盘目录：前者便宜，且与后台「当前占用」同一口径。
         */
        $quotaMb = (int) setting('attachment_quota', '0');

        if ($quotaMb > 0) {
            $used = (int) Database::value(
                'SELECT COALESCE(SUM(' . Database::identifier('size') . '), 0) FROM '
                . Database::identifier('attachments')
                . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL'
            );

            if ($used + $size > $quotaMb * 1048576) {
                throw new RuntimeException(
                    '附件空间已用尽：上限 ' . $quotaMb . ' MB，当前已占用 ' . format_size($used) . '。'
                    . '请先清理部分附件，或请管理员调高上限。'
                );
            }
        }

        // 真实 MIME（finfo 扩展随 PHP 默认启用，绝不信任 $_FILES['type'] 与文件名）
        $mime = self::detectMime($tmpPath);

        // 声明扩展名取自原始文件名；jpg / jpeg 视为同一类型
        $claimedExt = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($claimedExt === 'jpeg') {
            $claimedExt = 'jpg';
        }

        /*
         * 双重白名单，缺一不可：
         *  ① 后台「允许上传的扩展名」设置 —— 站长可以自行增删；
         *  ② 内置的 EXT_MAP（已知安全类型表）—— 防止配置里写了陌生扩展名被绕过。
         */
        $allowedExt = $isAvatar ? self::AVATAR_EXT : self::allowedExtensions();

        /*
         * 扩展名怎么定 —— 分两条路，**以文件内容为准**。
         *
         * A. 声明的扩展名本身可用（在名单里、已登记、且真实 MIME 落在它的期望列表内）
         *    → 原样使用。绝大多数正常上传都走这条。
         *
         * B. 否则看真实内容是不是一种「本站已开放的已知类型」：
         *    → 是，就按真实类型定扩展名（.png 其实是 JPEG，就存成 .jpg）。
         *      现实里"扩展名与内容不符"多半来自网页图片另存、工具改名、聊天软件转发，
         *      文件本身没问题，直接拒稿只会让人一头雾水。
         *    → 不是，才拒绝，并且把**真实格式**写进提示，让人知道该怎么改。
         *
         * 这不算放宽：存储扩展名始终来自可信来源（白名单 + 真实 MIME），
         * 声明扩展名只用来「选路」，从不直接落到文件名上。
         */
        $extension = null;

        if ($claimedExt !== ''
            && in_array($claimedExt, $allowedExt, true)
            && isset(self::EXT_MAP[$claimedExt])
            && self::mimeAllowed($mime, self::EXT_MAP[$claimedExt][0])
        ) {
            $extension = $claimedExt;
        } else {
            $realExt = self::extensionForMime($mime);

            if ($realExt !== null && in_array($realExt, $allowedExt, true)) {
                $extension = $realExt;
            }
        }

        if ($extension === null) {
            $realExt = self::extensionForMime($mime);

            // 提示里带上真实格式：看到「实际是 image/jpeg」，就知道该把扩展名改成 .jpg
            throw new RuntimeException(
                $realExt === null
                    ? '不支持的文件类型：' . e($mime) . '。'
                    : '这个文件的真实格式是 ' . e($mime) . '，对应扩展名 .' . $realExt
                        . '，当前站点没有开放；可在后台「附件」里把它加进允许的扩展名。'
            );
        }

        [$expectMimes, $isImage, $rename] = self::EXT_MAP[$extension];

        /*
         * 图片再用 getimagesize 复核一遍。解析不了**不拒绝**，而是降级成普通文件保存：
         * 有些合法图片（部分 avif / bmp / ico 编码）PHP 解析不了，但内容本身没问题，
         * 按文件存比直接拒稿合适，也避免误伤。
         */
        if ($isImage && @getimagesize($tmpPath) === false) {
            $isImage = false;
        }

        $hash = hash_file('sha256', $tmpPath) ?: '';

        /*
         * 源码 / 网页类改名保存：shell.php 落盘为 shell.php1。
         * 改名后它不再是 Web 服务器会识别的脚本类型，即便上传目录某天被直接访问，
         * 最多也只是下载到一份文本 —— 这是「允许交换源码」与「不让它可执行」的折中。
         * 展示给用户的文件名不受影响：附件表里的 name 仍是原始文件名。
         */
        if ($rename) {
            $extension .= '1';
        }

        /*
         * 头像单独放 avatars/，与帖子附件（uploads/）分开。
         *
         * 头像天生要展示给所有人（连游客都得看到别人的头像），所以它由 /media/{path}
         * 公开提供；而附件必须走 /attachment/{id} 的权限校验。两者混在同一个目录里时，
         * /media 就成了附件权限的后门。分目录后 /media 只认 avatars/ 前缀，边界清楚。
         */
        $relativeDir = ($isAvatar ? 'avatars' : 'uploads') . '/' . date('Y/m');
        $fileName    = date('His') . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $absoluteDir = APP_ROOT . '/storage/' . $relativeDir;

        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            throw new RuntimeException('无法创建上传目录，请检查 storage 目录权限。');
        }

        self::protectDirectory($absoluteDir);

        $absolute = $absoluteDir . '/' . $fileName;

        $moved = is_uploaded_file($tmpPath)
            ? move_uploaded_file($tmpPath, $absolute)
            : rename($tmpPath, $absolute);

        if (!$moved) {
            throw new RuntimeException('文件保存失败，请稍后重试。');
        }

        @chmod($absolute, 0644);

        return [
            'name'     => mb_substr(self::sanitizeName($original), 0, 120),
            'path'     => $relativeDir . '/' . $fileName,
            'mime'     => $mime,
            'size'     => $size,
            'hash'     => $hash,
            'is_image' => $isImage,
            'absolute' => $absolute,
        ];
    }

    /**
     * 删除已存储的文件
     */
    public static function remove(string $relativePath): bool
    {
        $absolute = self::absolutePath($relativePath);

        if ($absolute === null) {
            return false;
        }

        return @unlink($absolute);
    }

    /**
     * 把相对路径解析为 storage 下的绝对路径（阻断路径穿越）
     */
    public static function absolutePath(string $relativePath): ?string
    {
        $relativePath = str_replace('\\', '/', $relativePath);
        $relativePath = ltrim($relativePath, '/');

        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return null;
        }

        // 只允许白名单字符
        if (!preg_match('#^[A-Za-z0-9_\-./]+$#', $relativePath)) {
            return null;
        }

        $base     = realpath(APP_ROOT . '/storage') ?: APP_ROOT . '/storage';
        $absolute = realpath($base . '/' . $relativePath);

        if ($absolute === false || !str_starts_with($absolute, $base)) {
            return null;
        }

        return $absolute;
    }

    /**
     * 由真实 MIME 反推规范扩展名
     *
     * 用于「扩展名与内容不符」时的自动纠正：文件其实是 JPEG 却叫 .png，
     * 就由 image/jpeg 找到 jpg，落盘为 .jpg。
     *
     * 跳过第三列为 true 的源码 / 网页类 —— 它们的可接受 MIME 里含
     * `application/octet-stream` 这种兜底值，不跳过的话，任何 finfo 认不出的二进制
     * 都会被误判成 .php。
     *
     * @return string|null 未登记过的类型返回 null
     */
    private static function extensionForMime(string $mime): ?string
    {
        foreach (self::EXT_MAP as $ext => $rule) {
            if ($rule[2]) {
                continue;
            }

            if (self::mimeAllowed($mime, $rule[0])) {
                return $ext;
            }
        }

        return null;
    }

    /**
     * 真实 MIME 是否落在期望列表内（支持 'text/*' 这类前缀通配）
     *
     * @param list<string> $patterns
     */
    private static function mimeAllowed(string $mime, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_ends_with($pattern, '/*')) {
                if (str_starts_with($mime, substr($pattern, 0, -1))) {
                    return true;
                }
            } elseif ($mime === $pattern) {
                return true;
            }
        }

        return false;
    }

    /** 读取真实 MIME */
    private static function detectMime(string $path): string
    {
        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($path);
            if (is_string($mime) && $mime !== '') {
                return strtolower($mime);
            }
        }

        // 退化方案：使用 getimagesize 判断图片，其余一律视为二进制流
        $info = @getimagesize($path);
        if (is_array($info) && isset($info['mime'])) {
            return strtolower((string)$info['mime']);
        }

        return 'application/octet-stream';
    }

    /** 过滤文件名中的危险字符 */
    private static function sanitizeName(string $name): string
    {
        $name = str_replace(['\\', '/', "\0"], '', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? $name;

        return trim($name) === '' ? 'file' : trim($name);
    }

    /** 在目录中放置防护文件，避免上传目录被当作可执行目录 */
    private static function protectDirectory(string $dir): void
    {
        $storage = APP_ROOT . '/storage';

        $htaccess = $storage . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "php_flag engine off\nOptions -ExecCGI\nAddType text/plain .php .phtml .php3 .php4 .php5 .phar\n");
        }

        foreach ([$storage, $dir] as $target) {
            $index = rtrim($target, '/') . '/index.html';
            if (!is_file($index)) {
                @file_put_contents($index, '');
            }
        }
    }

    /** 上传错误码 => 中文提示 */
    private static function errorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '文件体积超出服务器限制。',
            UPLOAD_ERR_PARTIAL   => '文件只上传了一部分，请重试。',
            UPLOAD_ERR_NO_FILE   => '没有选择要上传的文件。',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录。',
            UPLOAD_ERR_CANT_WRITE => '服务器写入文件失败。',
            UPLOAD_ERR_EXTENSION  => '上传被服务器扩展中断。',
            default               => '上传失败，请稍后重试。',
        };
    }
}
