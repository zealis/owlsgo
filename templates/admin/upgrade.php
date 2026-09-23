<?php
/**
 * 后台在线升级页（manifest 清单 + 按文件比对）
 *
 * 变量：$repo、$branch（config 升级仓库/分支）、$current（本地版本）、
 *       $manifest（远端清单或 null）、$changes（compare() 结果）、
 *       $hasNew（远端 version > 本地）、$error（检测失败原因）
 */
?>
<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'refresh', 'size' => 16]) ?>在线升级</h3>
    </div>
    <div class="panel__body">
        <dl class="kv" style="margin:0 0 14px">
            <dt>当前版本</dt>
            <dd class="mono">v<?= e($current) ?></dd>

            <?php if ($repo === ''): ?>
                <dt>升级仓库</dt>
                <dd>未配置 —— 请在 <code>config/app.php</code> 的 <code>upgrade_repo</code> 填写 GitHub 仓库（owner/repo）。</dd>
            <?php elseif ($error !== ''): ?>
                <dt>检测状态</dt>
                <dd class="ow-text-danger"><?= e($error) ?></dd>
            <?php elseif ($manifest !== null): ?>
                <dt>升级源</dt>
                <dd class="mono"><code><?= e($repo . ' @ ' . $branch) ?></code></dd>

                <dt>远端版本</dt>
                <dd class="mono">
                    <?= e($manifest['version'] !== '' ? 'v' . ltrim($manifest['version'], 'vV') : $manifest['sha']) ?>
                    <?php if ($hasNew): ?>
                        <span class="ow-badge" style="background:var(--qq-soft);color:var(--qq-blue-deep);margin-inline-start:6px">有新版本</span>
                    <?php else: ?>
                        <span class="ow-text-light" style="margin-inline-start:6px">已是最新</span>
                    <?php endif; ?>
                </dd>

                <dt>清单标识</dt>
                <dd class="mono"><?= e(substr($manifest['sha'], 0, 12)) ?><?= $manifest['date'] !== '' ? ' · ' . e(mb_substr($manifest['date'], 0, 10)) : '' ?></dd>

                <?php if ($manifest['message'] !== ''): ?>
                    <dt>提交说明</dt>
                    <dd><?= e($manifest['message']) ?></dd>
                <?php endif; ?>

                <dt>文件变更</dt>
                <dd>
                    <?php if ($changes === []): ?>
                        <span class="ow-text-light">与本地完全一致</span>
                    <?php else: ?>
                        <?php
                        $counts = ['新增' => 0, '更新' => 0, '删除' => 0];
                        foreach ($changes as $c) {
                            $counts[$c['type']]++;
                        }
                        ?>
                        <span class="mono">
                            新增 <?= (int)$counts['新增'] ?> · 更新 <?= (int)$counts['更新'] ?> · 删除 <?= (int)$counts['删除'] ?>
                        </span>
                    <?php endif; ?>
                </dd>
            <?php endif; ?>
        </dl>

        <?php if ($repo !== '' && $manifest !== null && $changes !== []): ?>
            <form method="post" action="<?= e(url('/admin/upgrade/apply')) ?>">
                <?= csrf_field() ?>
                <div style="border:1px solid var(--qq-line);border-radius:8px;padding:10px 12px;max-height:280px;overflow:auto;margin-bottom:12px">
                    <?php foreach ($changes as $c): ?>
                        <?php
                        $badgeColor = $c['type'] === '删除' ? 'var(--qq-red)' : ($c['type'] === '新增' ? 'var(--qq-green)' : 'var(--qq-orange)');
                        ?>
                        <label style="display:flex;align-items:center;gap:8px;padding:3px 0;font-size:13px;cursor:pointer">
                            <input type="checkbox" name="files[]" value="<?= e($c['path']) ?>"
                                   <?= $c['type'] === '删除' ? '' : 'checked' ?>>
                            <span class="mono" style="color:<?= $badgeColor ?>;flex:none;font-size:12px;width:34px"><?= e($c['type']) ?></span>
                            <span class="mono" style="font-size:12.5px"><?= e($c['path']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="ow-text-light" style="font-size:12.5px;margin:0 0 12px">
                    被覆盖/删除的文件会先备份到 <code>storage/backup/</code>；<code>storage/</code> 用户数据与
                    <code>plugins/</code> 本地插件不受影响。升级完成后自动清理站点缓存与 OPcache。<br>
                    执行前请确认已完成数据库备份。
                </div>
                <button type="submit" class="ow-button">
                    <?= $view('partials/icon', ['name' => 'download', 'size' => 15]) ?>
                    <span>应用选中的变更</span>
                </button>
            </form>
        <?php elseif ($repo !== '' && $manifest !== null && $changes === [] && $error === ''): ?>
            <p class="ow-text-light" style="font-size:12.5px;margin:0">程序文件与升级源完全一致，无需升级。</p>
        <?php endif; ?>
    </div>
</section>
