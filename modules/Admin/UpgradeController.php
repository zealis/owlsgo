<?php
/**
 * 后台在线升级（manifest 清单 + 按文件 sha256 比对，参考 bbs1 更新器）
 *
 * 升级源 = 仓库里的 manifest.json（全量程序文件清单 + 每文件 sha256），
 * 由 GitHub Actions（.github/workflows/manifest.yml）在 push 时自动生成，
 * 或本地 .tools/build-manifest.php 手动生成后提交。官方 push 即可被检测到，
 * 不需要发 Release、不需要打 zip 包。
 *
 * 流程：
 *   1. GET  /admin/upgrade        —— 拉 manifest → 与本地逐文件 sha256 比对
 *      → 展示「新增/更新/删除」清单，管理员勾选；
 *   2. POST /admin/upgrade/apply  —— 对勾选的文件逐个下载（raw.githubusercontent.com）
 *      → sha256 校验 → 备份旧文件到 storage/backup/ → 原子写入/删除
 *      → 清站点缓存 + OPcache → 记录本次文件清单（用于下次发现「删除」）。
 *
 * 安全边界：
 *   - 路由层 admin.settings 权限 + CSRF；flock 防并发；
 *   - 白名单目录（core/modules/templates/public/config/sql/docs）双重过滤，
 *     storage/（用户数据）与 plugins/（本地插件）永不被触碰；
 *   - 下载内容 sha256 与清单一致才落盘；只请求 raw.githubusercontent.com。
 */

declare(strict_types=1);

namespace Modules\Admin;

use Core\Cache;
use Core\Controller;
use Core\Logger;
use Core\Request;
use Core\Response;
use Core\Router;
use Core\Updater;

final class UpgradeController extends Controller
{
    /** 检测清单缓存时长（秒）：GitHub raw 有内容缓存，避免每次进页都打源站 */
    private const MANIFEST_TTL = 600;

    private const MAX_MANIFEST_BYTES = 2097152;    // 2MB
    private const MAX_FILE_BYTES     = 4194304;    // 单文件 4MB

    /**
     * 升级检查页
     *
     * @param array<string, string> $params
     */
    public function index(array $params): never
    {
        $repo    = (string)config('app.upgrade_repo', '');
        $branch  = (string)config('app.upgrade_branch', 'main');
        $current = (string)config('app.version', '1.0.0');

        $manifest   = null;
        $changes    = [];
        $error      = '';
        $hasNew     = false;

        if ($repo !== '') {
            try {
                $manifest = $this->fetchManifest($repo, $branch);

                // 上次升级时的文件清单（用于发现「远端已删除」的文件）
                $previous = $this->previousFiles();

                $changes = Updater::compare($manifest['files'], APP_ROOT, $previous);

                // 远端 version 比本地新 → 顶部提示「有新版本」
                $remote = ltrim($manifest['version'], 'vV');
                $hasNew = $remote !== '' && version_compare($remote, $current, '>');
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        Response::html(\Core\View::render('admin/upgrade', [
            'pageTitle' => '在线升级',
            'repo'      => $repo,
            'branch'    => $branch,
            'current'   => $current,
            'manifest'  => $manifest,
            'changes'   => $changes,
            'hasNew'    => $hasNew,
            'error'     => $error,
        ]));
    }

    /**
     * 执行升级（按勾选的文件）
     *
     * @param array<string, string> $params
     */
    public function apply(array $params): never
    {
        $back = Router::url('/admin/upgrade');

        if (Request::method() !== 'POST') {
            $this->redirectWith($back, '非法请求。', 'error');
        }

        $repo   = (string)config('app.upgrade_repo', '');
        $branch = (string)config('app.upgrade_branch', 'main');

        if ($repo === '') {
            $this->redirectWith($back, '尚未配置升级仓库（config/app.php 的 upgrade_repo）。', 'error');
        }

        // flock 防并发升级
        $lockFile = APP_ROOT . '/storage/upgrade.lock';
        $lockDir = dirname($lockFile);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }
        $lock = @fopen($lockFile, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            $this->redirectWith($back, '已有一次升级正在进行，请稍后再试。', 'error');
        }

        try {
            $manifest = $this->fetchManifest($repo, $branch);
            $previous = $this->previousFiles();
            $changes  = Updater::compare($manifest['files'], APP_ROOT, $previous);

            $paths = Request::array('files');

            $result = Updater::applyChanges(
                $paths,
                $changes,
                function (string $path) use ($repo, $branch): string {
                    $content = $this->httpGet($this->rawUrl($repo, $branch, $path), self::MAX_FILE_BYTES);
                    $expected = $this->currentManifest['files'][$path] ?? null;
                    if ($expected === null || !hash_equals($expected, hash('sha256', $content))) {
                        throw new \RuntimeException($path . ' 的内容与清单校验值不一致，已中止（防下载被篡改）。');
                    }
                    return $content;
                },
                APP_ROOT,
                $manifest['sha']
            );

            // 记录本次应用的文件清单：下次比对才能发现「远端已删除」的文件
            $this->writeState($manifest);

            Cache::flush();
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            Logger::info('在线升级完成', [
                'remote'  => $manifest['sha'],
                'written' => $result['written'],
                'deleted' => $result['deleted'],
                'backup'  => $result['backupDir'],
            ]);

            $this->redirectWith($back, sprintf(
                '升级完成：%d 个文件写入、%d 个删除。旧文件已备份到 %s。已清理站点缓存与 OPcache。',
                $result['written'],
                $result['deleted'],
                $result['backupDir']
            ));
        } catch (\Throwable $e) {
            Logger::warning('在线升级失败', ['error' => $e->getMessage()]);
            $this->redirectWith($back, '升级失败：' . $e->getMessage(), 'error');
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** 拉取并解析 manifest（10 分钟内存缓存，避免频繁打 GitHub raw） */
    private function fetchManifest(string $repo, string $branch): array
    {
        $cached = Cache::get('upgrade:manifest');
        if (is_array($cached) && ($cached['fetched_at'] ?? 0) > time() - self::MANIFEST_TTL) {
            $this->currentManifest = $cached['manifest'];
            return $cached['manifest'];
        }

        $raw = $this->httpGet($this->rawUrl($repo, $branch, 'manifest.json'), self::MAX_MANIFEST_BYTES);
        $manifest = Updater::parseManifest($raw);

        $this->currentManifest = $manifest;
        Cache::set('upgrade:manifest', ['fetched_at' => time(), 'manifest' => $manifest], self::MANIFEST_TTL);

        return $manifest;
    }

    /** raw.githubusercontent.com 的文件地址 */
    private function rawUrl(string $repo, string $branch, string $path): string
    {
        return 'https://raw.githubusercontent.com/' . $repo . '/' . $branch . '/' . $path;
    }

    /** 上次升级时的文件清单（storage/upgrade-state.json） */
    private function previousFiles(): array
    {
        $file = APP_ROOT . '/storage/upgrade-state.json';
        if (!is_file($file)) {
            return [];
        }
        $state = json_decode((string)file_get_contents($file), true);
        return is_array($state['files'] ?? null) ? $state['files'] : [];
    }

    private function writeState(array $manifest): void
    {
        $file = APP_ROOT . '/storage/upgrade-state.json';
        @file_put_contents($file, json_encode([
            'sha'   => $manifest['sha'],
            'date'  => date('c'),
            'files' => $manifest['files'],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    }

    private array $currentManifest = [];

    /**
     * HTTPS-only GET，只允许 GitHub raw / API 域名
     * SSL 严格优先，证书链问题（自签代理环境）才放宽重试一次。
     */
    private function httpGet(string $url, int $maxBytes): string
    {
        if (!preg_match('#^https://(raw\.githubusercontent\.com|api\.github\.com|github\.com)/#', $url)) {
            throw new \RuntimeException('拒绝请求非 GitHub 地址：' . $url);
        }

        if (!function_exists('curl_init')) {
            throw new \RuntimeException('当前 PHP 未启用 curl 扩展，无法在线检测。');
        }

        $body = $this->curlGet($url, $maxBytes, true);
        if ($body === null && in_array($this->lastCurlErrno, [60, 77], true)) {
            Logger::warning('GitHub 请求证书校验失败，已放宽校验重试', ['errno' => $this->lastCurlErrno]);
            $body = $this->curlGet($url, $maxBytes, false);
        }

        if ($body === null) {
            throw new \RuntimeException('连接 GitHub 失败（errno ' . $this->lastCurlErrno . '）；国内网络环境下可稍后重试。');
        }

        return $body;
    }

    private int $lastCurlErrno = 0;

    private function curlGet(string $url, int $maxBytes, bool $verify): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('无法初始化网络请求。');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => 'owlsgo-updater/' . (string)config('app.version', '1.0'),
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ]);

        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $this->lastCurlErrno = (int)curl_errno($ch);
        curl_close($ch);

        if ($body === false) {
            return null;
        }
        if ($status !== 200) {
            if ($status === 404) {
                throw new \RuntimeException('升级清单不存在（HTTP 404）——仓库里还没有 manifest.json，'
                    . '请先推送代码触发 GitHub Actions 生成，或本地运行 .tools/build-manifest.php 后提交。');
            }
            if ($status === 403 || $status === 429) {
                throw new \RuntimeException('GitHub 限流（HTTP ' . $status . '），请稍后再试。');
            }
            throw new \RuntimeException('GitHub 返回 HTTP ' . $status . '。');
        }
        if (strlen((string)$body) > $maxBytes) {
            throw new \RuntimeException('下载内容超过大小上限。');
        }

        return (string)$body;
    }
}
