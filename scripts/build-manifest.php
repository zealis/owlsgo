<?php
/**
 * 生成 manifest.json（在线升级清单：全量程序文件 + 每文件 sha256）
 *
 * 用法：
 *   php scripts/build-manifest.php          → 在仓库根生成/更新 manifest.json
 *   php scripts/build-manifest.php --stdout → 只打印 JSON（供 CI 使用）
 *
 * GitHub Actions（.github/workflows/manifest.yml）在 push 时自动调用；
 * 不想用 Actions 时也可以手动跑一次并提交 manifest.json。
 *
 * 清单只包含白名单目录（core/modules/templates/public/config/sql/docs），
 * storage/、plugins/ 等用户数据永远不会进入清单。
 */
define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/core/bootstrap.php';

$manifest = \Core\Updater::buildManifest(APP_ROOT, [
    // 有 git 时带上 commit 信息，便于在升级页展示「这次更新是什么」
    'sha'     => trim((string)@shell_exec('git rev-parse HEAD 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'))) ?: '',
    'message' => trim((string)@shell_exec('git log -1 --pretty=%B 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'))) ?: '',
    'date'    => gmdate('c'),
]);

$json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

if (in_array('--stdout', $argv, true)) {
    echo $json, "\n";
    exit(0);
}

$target = APP_ROOT . '/manifest.json';
if (@file_put_contents($target, $json . "\n", LOCK_EX) === false) {
    fwrite(STDERR, "无法写入 $target\n");
    exit(1);
}

echo 'manifest.json 已生成：', count($manifest['files']), " 个文件，sha=", substr($manifest['sha'], 0, 12), "\n";
echo "请提交并推送：git add manifest.json && git commit -m 'chore: update manifest' && git push\n";
