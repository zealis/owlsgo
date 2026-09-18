<?php
/**
 * 图形验证码（零依赖实现）
 *
 * 为什么画成 SVG 而不是 PNG：
 *   本项目的定位是「零依赖」，GD 扩展在安装向导里只算「可选」级别。
 *   若用 GD 画图，没装 GD 的机器上验证码会直接失效（而且是在登录这种
 *   关键路径上失效，等于把人锁在门外）。SVG 只需要拼字符串，任何 PHP
 *   环境都能输出，现代浏览器也原生支持。
 *
 * 防护程度说明：
 *   它能挡住批量撞库脚本、无脑爬虫这类「不会看图」的自动化程序，
 *   但 SVG 里的字符仍然是文本节点，挡不住专门做验证码识别的攻击者。
 *   这属于「提高门槛」而非「绝对安全」——真正的防线是登录限流
 *   （`config/app.php` 的 rate_limit.login）+ 强密码。
 *
 * 字符集刻意剔除了 0/O、1/I/L 这类肉眼易混的字符，避免用户
 * 「明明看对了却提示错误」的挫败感。
 */

declare(strict_types=1);

namespace Core;

final class Captcha
{
    /** 验证码位数 */
    private const LENGTH = 4;

    /** 有效期（秒）——超过即作废，防止一张图挂很久被慢慢试探 */
    private const TTL = 300;

    /** 字符集：去掉 0 O 1 I L 等易混字符 */
    private const CHARS = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /**
     * 会话键名按用途区分
     *
     * 登录与注册可能同时开着（比如用户开了两个标签页），
     * 若共用一个键，后生成的会把先生成的覆盖掉，导致另一张图必然失效。
     */
    private static function sessionKey(string $scope): string
    {
        return 'captcha_' . $scope;
    }

    /**
     * 对应场景是否开启了验证码
     *
     * @param string $scope login | register
     */
    public static function enabled(string $scope = 'login'): bool
    {
        return $scope === 'register'
            ? Settings::bool('register_captcha', false)
            : Settings::bool('login_captcha', false);
    }

    /**
     * 生成一组验证码写入会话，并返回它的 SVG 图像
     *
     * @param string $scope login | register
     */
    public static function render(string $scope = 'login'): string
    {
        $code = self::randomCode();

        Session::set(self::sessionKey($scope), [
            'code' => $code,
            'time' => time(),
        ]);

        return self::svg($code);
    }

    /**
     * 校验用户输入
     *
     * 两条铁律：
     *   1. 不区分大小写（用户不该为大小写负责）；
     *   2. 无论成功还是失败都用后即焚 —— 否则同一张图可以被反复试探，
     *      4 位字符集 31 个字符，试几千次就撞开了。
     *
     * @param string $scope login | register
     */
    public static function verify(string $input, string $scope = 'login'): bool
    {
        $key    = self::sessionKey($scope);
        $stored = Session::get($key);

        Session::delete($key);

        if (!is_array($stored)) {
            return false;
        }

        $code = (string)($stored['code'] ?? '');
        $time = (int)($stored['time'] ?? 0);

        if ($code === '' || $time <= 0 || (time() - $time) > self::TTL) {
            return false;
        }

        return strtoupper(trim($input)) === $code;
    }

    /** 随机取码 */
    private static function randomCode(): string
    {
        $max  = strlen(self::CHARS) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::CHARS[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * 把字符绘制成 SVG
     */
    private static function svg(string $code): string
    {
        $width  = 132;
        $height = 44;
        $len    = strlen($code);
        $step   = $width / ($len + 1);

        $svg   = [];
        $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height
            . '" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="图形验证码">';
        $svg[] = '<rect width="100%" height="100%" fill="#f0f6fc"/>';

        // 干扰线：让自动切分字符变难
        for ($i = 0; $i < 4; $i++) {
            $svg[] = sprintf(
                '<path d="M%d %d Q%d %d %d %d" stroke="%s" stroke-width="1" fill="none" opacity="0.6"/>',
                random_int(0, $width),
                random_int(0, $height),
                random_int(0, $width),
                random_int(0, $height),
                random_int(0, $width),
                random_int(0, $height),
                self::pick(['#9dc6e8', '#b6d6ef', '#8fb9dd'])
            );
        }

        // 噪点
        for ($i = 0; $i < 16; $i++) {
            $svg[] = sprintf(
                '<circle cx="%d" cy="%d" r="1" fill="%s" opacity="0.55"/>',
                random_int(0, $width),
                random_int(0, $height),
                self::pick(['#a9c9e4', '#7fb3d8'])
            );
        }

        // 字符：逐个随机旋转与偏移，破坏整齐排列
        for ($i = 0; $i < $len; $i++) {
            $x     = (int)round($step * ($i + 1));
            $y     = (int)round($height / 2 + random_int(-3, 3));
            $angle = random_int(-18, 18);
            $size  = random_int(20, 24);
            $color = self::pick(['#0b4f7d', '#12608f', '#1a6fa0', '#0d5c8a']);

            $svg[] = sprintf(
                '<text x="%d" y="%d" font-family="ui-monospace,Consolas,monospace" font-size="%d"'
                . ' font-weight="600" fill="%s" text-anchor="middle" dominant-baseline="central"'
                . ' transform="rotate(%d %d %d)">%s</text>',
                $x,
                $y,
                $size,
                $color,
                $angle,
                $x,
                $y,
                htmlspecialchars($code[$i], ENT_QUOTES, 'UTF-8')
            );
        }

        $svg[] = '</svg>';

        return implode('', $svg);
    }

    /**
     * 从候选里随机取一个
     *
     * @param list<string> $items
     */
    private static function pick(array $items): string
    {
        return $items[random_int(0, count($items) - 1)];
    }
}
