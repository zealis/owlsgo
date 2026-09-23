<?php
/**
 * 在线升级核心逻辑（manifest 清单 + 按文件 sha256 比对，参考 bbs1 更新器）
 *
 * 与 GitHub Releases 的解耦：升级源是一份 **manifest.json**（放在仓库里），
 * 内容 = 全量程序文件清单 + 每个文件的 sha256。官方 push 代码后由
 * GitHub Actions（.github/workflows/manifest.yml）或本地脚本
 * （.tools/build-manifest.php）重新生成并提交 —— 所以「push 即可被检测到」，
 * 不需要发 Release、不需要打 zip 包。
 *
 * manifest.json 结构：
 * {
 *   "sha":      "ab12cd34ef56…"   本次清单对应的 commit（或内容哈希），
 *   "date":     "2026-09-22T10:00:00+08:00",
 *   "version":  "1.1.0"           清单对应的程序版本（取自 config/app.php），
 *   "message":  "commit message 首行",
 *   "files":    { "相对路径": "sha256", … }
 * }
 *
 * 本类是纯逻辑（不发起网络请求）：下载由调用方注入 fetch 回调，
 * 因此可以在本地用「文件系统假站点」做全流程测试。
 */

declare(strict_types=1);

namespace Core;

use RuntimeException;

final class Updater
{
    /** 允许升级覆盖/删除的目录前缀（仓库相对路径） */
    public const WHITELIST_PREFIXES = ['core/', 'modules/', 'templates/', 'public/', 'config/', 'sql/', 'docs/'];

    /** 无论出现在哪里都拒绝覆盖的文件 */
    private const PROTECTED_FILES = ['storage/config/secret.key', 'install.lock'];

    /**
     * 生成 manifest（.tools/build-manifest.php 与 CI workflow 共用）
     *
     * @param  string $appRoot 站点根（规范副本根目录）
     * @param  array  $meta    ['sha'=>?, 'date'=>?, 'message'=>?]；缺省时自动推导
     * @return array  完整 manifest 数组
     */
    public static function buildManifest(string $appRoot, array $meta = []): array
    {
        $files = [];
        self::collectDir($appRoot, $files);

        $sha = (string)($meta['sha'] ?? '');
        $message = trim((string)($meta['message'] ?? ''));

        if ($sha === '') {
            // 不在 git 仓库内时，退化为「清单内容哈希」，保证 sha 随内容变化
            $sha = hash('sha256', implode("\n", array_map(
                static fn(string $p, string $h): string => $p . ':' . $h,
                array_keys($files),
                array_values($files)
            )));
        }

        return [
            'sha'     => $sha,
            'date'    => (string)($meta['date'] ?? gmdate('c')),
            'version' => (string)config('app.version', '1.0.0'),
            'message' => $message !== '' ? strtok($message, "\r\n") : '',
            'files'   => $files,
        ];
    }

    /** 递归收集白名单目录下的文件 → ['相对路径' => 'sha256'] */
    private static function collectDir(string $appRoot, array &$out): void
    {
        foreach (self::WHITELIST_PREFIXES as $prefix) {
            $base = rtrim($prefix, '/');
            $dir = $appRoot . '/' . $base;
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $dir,
                \FilesystemIterator::SKIP_DOTS
            ));
            /** @var \SplFileInfo $f */
            foreach ($it as $f) {
                if (!$f->isFile()) {
                    continue;
                }
                $relative = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1)), '/');
                $out[$prefix . $relative] = hash_file('sha256', $f->getPathname());
            }
        }
    }

    /**
     * 校验并解析 manifest JSON
     *
     * @return array{sha:string, date:string, version:string, message:string, files:array<string,string>}
     */
    public static function parseManifest(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['files']) || !is_array($data['files'])) {
            throw new RuntimeException('升级源返回的清单格式无效。');
        }

        $sha = (string)($data['sha'] ?? '');
        if (!preg_match('/^[a-f0-9]{16,64}$/D', $sha)) {
            throw new RuntimeException('升级源清单的版本标识无效。');
        }

        $files = [];
        foreach ($data['files'] as $path => $hash) {
            if (!is_string($path) || !is_string($hash)) {
                continue;
            }
            // 清单本身也可能被篡改：路径与哈希都要过一遍白名单
            if (!self::isUpgradable($path) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                continue;
            }
            $files[$path] = $hash;
        }

        if ($files === []) {
            throw new RuntimeException('升级源清单为空（没有可升级的文件）。');
        }

        return [
            'sha'     => $sha,
            'date'    => (string)($data['date'] ?? ''),
            'version' => (string)($data['version'] ?? ''),
            'message' => trim((string)($data['message'] ?? '')),
            'files'   => $files,
        ];
    }

    /**
     * 逐文件比对：远端清单 vs 本地
     *
     * @param  array<string,string> $remoteFiles   远端清单（已过白名单）
     * @param  string               $appRoot       本地站点根
     * @param  array<string,true>   $previousFiles 上次升级时的文件清单（用于发现「远端已删除」；首次为空）
     * @return list<array{path:string, type:string}>  type ∈ 新增|更新|删除
     */
    public static function compare(array $remoteFiles, string $appRoot, array $previousFiles = []): array
    {
        $changes = [];
        foreach ($remoteFiles as $path => $hash) {
            $local = $appRoot . '/' . $path;
            if (!is_file($local)) {
                $changes[] = ['path' => $path, 'type' => '新增'];
            } elseif (!hash_equals($hash, (string)hash_file('sha256', $local))) {
                $changes[] = ['path' => $path, 'type' => '更新'];
            }
        }

        foreach (array_keys($previousFiles) as $path) {
            if (is_string($path) && !isset($remoteFiles[$path]) && self::isUpgradable($path) && is_file($appRoot . '/' . $path)) {
                $changes[] = ['path' => $path, 'type' => '删除'];
            }
        }

        usort($changes, static fn(array $a, array $b): int => [$a['type'], $a['path']] <=> [$b['type'], $b['path']]);

        return $changes;
    }

    /**
     * 应用选中的变更
     *
     * @param list<string>          $paths         用户勾选的路径（必须都在 $changes 里）
     * @param array                 $changes       compare() 的结果
     * @param callable              $fetch         function(string $path): string  —— 下载远端文件内容
     * @param string                $appRoot       本地站点根
     * @param string                $remoteSha     本次升级对应的远端标识（用于备份目录名）
     * @return array{written:int, deleted:int, backupDir:string}
     */
    public static function applyChanges(array $paths, array $changes, callable $fetch, string $appRoot, string $remoteSha): array
    {
        // 只允许勾选「检测出来的变更」，路径与类型以服务端比对结果为准
        $byPath = [];
        foreach ($changes as $change) {
            $byPath[$change['path']] = $change['type'];
        }

        $selected = [];
        foreach ($paths as $path) {
            if (!is_string($path) || !isset($byPath[$path])) {
                continue;   // 未检测到的路径一律忽略（防伪造）
            }
            if (!self::isUpgradable($path)) {
                continue;
            }
            $selected[$path] = $byPath[$path];
        }

        if ($selected === []) {
            throw new RuntimeException('请至少选择一个需要执行的升级操作。');
        }

        // 备份目录：storage/backup/{sha前12位}-{时间}/
        $backupDir = 'backup/' . substr($remoteSha, 0, 12) . '-' . date('Ymd-His');
        $backupAbs = $appRoot . '/storage/' . $backupDir;
        $written = 0;
        $deleted = 0;
        $backedUp = 0;

        foreach ($selected as $path => $type) {
            $target = $appRoot . '/' . $path;

            // 备份旧文件（删除/更新都要，新增无）
            if ($type !== '新增' && is_file($target)) {
                $dest = $backupAbs . '/' . $path;
                if (!is_dir(dirname($dest))) {
                    @mkdir(dirname($dest), 0755, true);
                }
                if (copy($target, $dest)) {
                    $backedUp++;
                }
            }

            if ($type === '删除') {
                if (@unlink($target)) {
                    $deleted++;
                }
                continue;
            }

            $content = $fetch($path);
            $expected = null;
            // fetch 闭包由控制器绑定清单，这里不重复校验哈希（控制器负责）
            if (!is_dir(dirname($target))) {
                @mkdir(dirname($target), 0755, true);
            }
            if (@file_put_contents($target, $content, LOCK_EX) === false) {
                throw new RuntimeException('无法写入 ' . $path . '（目录权限不足？）');
            }
            $written++;
        }

        // 升级请求本身就跑在 php-fpm/php-cgi 里，直接重置当前 SAPI 的 OPcache：
        // 不做的话，新写入的 PHP 文件最长 60s（revalidate_freq）内新旧字节码混跑，
        // 表现为「升级完成后短时间内行为诡异」。旧内核没有 OPcache 时函数不存在，跳过。
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return [
            'written'   => $written,
            'deleted'   => $deleted,
            'backupDir' => 'storage/' . $backupDir . '（' . $backedUp . ' 个旧文件）',
        ];
    }

    /** 路径是否允许升级覆盖/删除：白名单前缀 + 无目录穿越 + 保护文件 */
    public static function isUpgradable(string $path): bool
    {
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/') || str_contains($path, '\\')) {
            return false;
        }

        $allowed = false;
        foreach (self::WHITELIST_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            return false;
        }

        foreach (self::PROTECTED_FILES as $protected) {
            if ($path === $protected) {
                return false;
            }
        }

        return true;
    }
}
