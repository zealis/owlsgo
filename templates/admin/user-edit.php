<?php
/**
 * 后台：用户详情 / 编辑 / 封禁
 *
 * 变量：$profile（已 decorate）、$groups、$ban（一条封禁记录或 null）、
 *       $isSelf、$isSuper（目标用户是超级管理员）、$mutedGroup、$canBan、$stats
 *
 * 防呆提示：控制器会拒绝「修改自己的用户组」与「禁用自己的账号」，
 * 这里把对应控件直接置灰，避免用户白填一遍表单才被驳回。
 */

declare(strict_types=1);

$profile    = is_array($profile ?? null) ? $profile : [];
$groups     = is_array($groups ?? null) ? $groups : [];
$ban        = is_array($ban ?? null) ? $ban : null;
$isSelf     = (bool)($isSelf ?? false);
$isSuper    = (bool)($isSuper ?? false);
$stats      = is_array($stats ?? null) ? $stats : [];
$canBan     = (bool)($canBan ?? false);

$userId      = (int)($profile['id'] ?? 0);
$status      = (int)($profile['status'] ?? 1) === 1;
$groupColor  = (string)($profile['group_color'] ?? '#999999');
$expiresAt   = (int)($ban['expires_at'] ?? 0);
$banExpired  = $ban !== null && $expiresAt > 0 && $expiresAt < time();
$createdAt   = (int)($profile['created_at'] ?? 0);
?>

<!-- 概览 -->
<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'user', 'size' => 16]) ?>用户概览</h3>
        <span class="spacer"></span>
        <a href="<?= e(url('/u/' . $userId)) ?>" target="_blank" rel="noopener" style="font-size:13px">
            查看前台主页
        </a>
    </div>
    <div class="panel__body">
        <div class="ow-hstack" style="align-items:flex-start;gap:16px;flex-wrap:wrap">
            <?= avatar_img($profile, 64) ?>

            <div style="min-width:0;flex:1 1 260px">
                <div class="ow-hstack" style="gap:8px;flex-wrap:wrap">
                    <strong style="font-size:17px;color:<?= e($groupColor) ?>">
                        <?= e((string)($profile['username'] ?? '')) ?>
                    </strong>
                    <span class="ow-badge ow-outline" style="color:<?= e($groupColor) ?>">
                        <?= e((string)($profile['group_name'] ?? '游客')) ?>
                    </span>
                    <?php if ($status): ?>
                        <span class="ow-badge ow-outline"><span class="status-dot"></span>正常</span>
                    <?php else: ?>
                        <span class="ow-badge ow-outline"><span class="status-dot status-dot--off"></span>已禁用</span>
                    <?php endif; ?>
                    <?php if ($ban !== null): ?>
                        <span class="ow-badge" data-ow-variant="danger">
                            <?= $banExpired ? '封禁已过期' : '封禁中' ?>
                        </span>
                    <?php endif; ?>
                </div>

                <dl class="kv" style="margin-top:12px">
                    <dt>用户 ID</dt><dd class="mono">#<?= $userId ?></dd>
                    <dt>邮箱</dt><dd class="mono"><?= e((string)($profile['email'] ?? '')) ?></dd>
                    <dt>注册时间</dt>
                    <dd>
                        <?php if ($createdAt > 0): ?>
                            <?= e(date('Y-m-d H:i', $createdAt)) ?>
                            <span class="ow-text-light">（<?= e(human_time($createdAt)) ?>）</span>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </dd>
                    <dt>注册 IP</dt><dd class="mono"><?= e((string)($profile['register_ip'] ?? '') !== '' ? (string)$profile['register_ip'] : '—') ?></dd>
                    <dt>最后登录</dt>
                    <dd>
                        <?= e(human_time((int)($profile['last_login_at'] ?? 0))) ?>
                        <span class="ow-text-light mono"><?= e((string)($profile['last_login_ip'] ?? '')) ?></span>
                    </dd>
                </dl>
            </div>

            <div class="admin-cards" style="flex:0 0 auto;grid-template-columns:repeat(3,minmax(84px,1fr));gap:8px">
                <div class="admin-card" style="padding:10px 12px">
                    <div>
                        <div class="admin-card__value" style="font-size:18px"><?= number_format((int)($stats['threads'] ?? 0)) ?></div>
                        <div class="admin-card__label">帖子</div>
                    </div>
                </div>
                <div class="admin-card" style="padding:10px 12px">
                    <div>
                        <div class="admin-card__value" style="font-size:18px"><?= number_format((int)($stats['comments'] ?? 0)) ?></div>
                        <div class="admin-card__label">评论</div>
                    </div>
                </div>
                <div class="admin-card" style="padding:10px 12px">
                    <div>
                        <div class="admin-card__value" style="font-size:18px"><?= number_format((int)($stats['favorites'] ?? 0)) ?></div>
                        <div class="admin-card__label">收藏</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- 资料编辑 -->
<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'edit', 'size' => 16]) ?>资料编辑</h3>
    </div>
    <div class="panel__body">
        <?php if ($isSelf): ?>
            <div class="doc-note ow-hstack" style="gap:10px;align-items:flex-start;margin-bottom:14px">
                <?= $view('partials/icon', ['name' => 'info', 'size' => 18]) ?>
                <div>这是你自己的账号，用户组与启用状态已锁定，无法在此修改。</div>
            </div>
        <?php elseif ($isSuper): ?>
            <div role="alert" data-ow-variant="warning" style="margin-bottom:14px">
                <?= $view('partials/icon', ['name' => 'alert', 'size' => 18]) ?>
                <div>对方是超级管理员，调整权限前请确认你清楚后果。</div>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/admin/users/' . $userId)) ?>">
            <?= csrf_field() ?>

            <div class="form-grid">
                <div data-ow-field>
                    <label for="user-username">用户名</label>
                    <input type="text" id="user-username" name="username" maxlength="20" required
                           value="<?= e((string)old('username', (string)($profile['username'] ?? ''))) ?>">
                    <?php if (old_error('username') !== ''): ?>
                        <span class="field-error"><?= e(old_error('username')) ?></span>
                    <?php endif; ?>
                </div>

                <div data-ow-field>
                    <label for="user-email">邮箱</label>
                    <input type="email" id="user-email" name="email" maxlength="191" required
                           value="<?= e((string)old('email', (string)($profile['email'] ?? ''))) ?>">
                    <?php if (old_error('email') !== ''): ?>
                        <span class="field-error"><?= e(old_error('email')) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-grid">
                <div data-ow-field>
                    <label for="user-group">用户组</label>
                    <select id="user-group" name="group_id" <?= $isSelf ? 'disabled' : '' ?>>
                        <?php foreach ($groups as $groupId => $groupName): ?>
                            <option value="<?= (int)$groupId ?>"
                                <?= selected((int)($profile['group_id'] ?? 0), (int)$groupId) ?>>
                                <?= e((string)$groupName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isSelf): ?>
                        <input type="hidden" name="group_id" value="<?= (int)($profile['group_id'] ?? 0) ?>">
                        <span data-ow-hint>不能修改自己的用户组。</span>
                    <?php endif; ?>
                    <?php if (old_error('group_id') !== ''): ?>
                        <span class="field-error"><?= e(old_error('group_id')) ?></span>
                    <?php endif; ?>
                </div>

                <div data-ow-field>
                    <label for="user-points">积分</label>
                    <input type="number" id="user-points" name="points" min="0" max="99999999"
                           value="<?= e((string)old('points', (string)(int)($profile['points'] ?? 0))) ?>">
                </div>
            </div>

            <div class="form-grid">
                <div data-ow-field>
                    <label for="user-location">所在地</label>
                    <input type="text" id="user-location" name="location" maxlength="60"
                           value="<?= e((string)old('location', (string)($profile['location'] ?? ''))) ?>">
                </div>

                <div data-ow-field>
                    <label for="user-bio">个人简介</label>
                    <textarea id="user-bio" name="bio" rows="3" maxlength="100"><?= e((string)old('bio', (string)($profile['bio'] ?? ''))) ?></textarea>
                    <span class="field-hint">显示在个人主页与楼层下方，最多 100 个字符。</span>
                </div>
            </div>

            <label class="ow-hstack" style="gap:8px;align-items:flex-start;margin-bottom:14px">
                <input type="checkbox" name="status" value="1"
                    <?= checked($status) ?> <?= $isSelf ? 'disabled' : '' ?>>
                <?php if ($isSelf): ?>
                    <input type="hidden" name="status" value="1">
                <?php endif; ?>
                <span>
                    <strong style="font-size:14px">启用该账号</strong>
                    <span class="ow-text-light" style="display:block;font-size:12.5px">
                        取消勾选后该用户将无法登录（已有会话不会立即失效）。
                        <?= $isSelf ? '不能停用你自己的账号。' : '' ?>
                    </span>
                </span>
            </label>
            <?php if (old_error('status') !== ''): ?>
                <span class="field-error"><?= e(old_error('status')) ?></span>
            <?php endif; ?>

            <button type="submit" class="ow-button">
                <?= $view('partials/icon', ['name' => 'check', 'size' => 16]) ?>
                <span>保存资料</span>
            </button>
        </form>
    </div>
</section>

<!-- 版主版块：版主指派的唯一入口（原来的版块表单多选已移除） -->
<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'users', 'size' => 16]) ?>版主版块</h3>
        <span class="spacer"></span>
        <span class="ow-text-light" style="font-size:12.5px">勾选 <?= e((string)$profile['username']) ?> 担任版主的版块</span>
    </div>
    <form method="post" action="<?= e(url('/admin/users/' . (int)$profile['id'] . '/moderates')) ?>">
        <?= csrf_field() ?>
        <div class="panel__body">
            <?php if (($moderateForums ?? []) === []): ?>
                <p class="ow-text-light" style="margin-top:0">站点还没有任何版块。</p>
            <?php else: ?>
                <p class="ow-text-light" style="font-size:13px;margin-top:0">
                    被指派的用户在对应版块内拥有加精、置顶、删帖与审核权限；取消勾选并保存即移除。
                    保存后自动加入/移出「版主」用户组（管理员与超管不受影响）；版主组的用户未指派版块时不再拥有任何管理权。
                </p>
                <div style="display:flex;flex-direction:column;gap:8px;max-width:480px">
                    <?php foreach ($moderateForums as $forum): ?>
                        <label style="display:flex;align-items:center;gap:8px">
                            <input type="checkbox" name="forum_ids[]" value="<?= (int)$forum['id'] ?>"
                                <?= $forum['isModerating'] ? 'checked' : '' ?>>
                            <span><?= e($forum['name']) ?>（#<?= (int)$forum['id'] ?>）</span>
                            <?php if (!$forum['status']): ?>
                                <span class="ow-badge ow-outline">已隐藏</span>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="panel__foot">
            <?php if (($moderateForums ?? []) !== []): ?>
                <button type="submit" class="ow-button">
                    <?= $view('partials/icon', ['name' => 'check', 'size' => 16]) ?>
                    <span>保存版主版块</span>
                </button>
            <?php endif; ?>
        </div>
    </form>
</section>

<!-- 封禁 -->
<?php if ($canBan): ?>
    <section class="panel">
        <div class="panel__head">
            <h3><?= $view('partials/icon', ['name' => 'ban', 'size' => 16]) ?>封禁管理</h3>
            <span class="spacer"></span>
            <?php if ($ban !== null): ?>
                <span class="ow-badge" data-ow-variant="<?= $banExpired ? 'warning' : 'danger' ?>">
                    <?= $banExpired ? '存在已过期的封禁' : '封禁生效中' ?>
                </span>
            <?php else: ?>
                <span class="ow-badge ow-outline">未封禁</span>
            <?php endif; ?>
        </div>

        <div class="panel__body">
            <?php if ($isSelf): ?>
                <div class="doc-note ow-hstack" style="gap:10px;align-items:flex-start">
                    <?= $view('partials/icon', ['name' => 'info', 'size' => 18]) ?>
                    <div>不能封禁自己的账号。</div>
                </div>
            <?php else: ?>

                <?php if ($ban !== null): ?>
                    <dl class="kv" style="margin-bottom:14px">
                        <dt>封禁对象</dt><dd class="mono"><?= e((string)($ban['type'] ?? 'user')) ?> · <?= e((string)($ban['value'] ?? '')) ?></dd>
                        <dt>原因</dt><dd><?= e((string)($ban['reason'] ?? '') !== '' ? (string)$ban['reason'] : '未填写') ?></dd>
                        <dt>解封时间</dt>
                        <dd>
                            <?php if ($expiresAt === 0): ?>
                                <span class="ow-badge" data-ow-variant="danger">永久封禁</span>
                            <?php else: ?>
                                <?= e(date('Y-m-d H:i', $expiresAt)) ?>
                                <?php if ($banExpired): ?>
                                    <span class="ow-text-light">（已过期，实际已可登录）</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </dd>
                        <dt>操作时间</dt><dd><?= e(date('Y-m-d H:i', (int)($ban['created_at'] ?? 0))) ?></dd>
                    </dl>

                    <form method="post" action="<?= e(url('/admin/users/' . $userId . '/unban')) ?>"
                          data-confirm="确定要解除该用户的封禁吗？">
                        <?= csrf_field() ?>
                        <button type="submit" class="ow-button">
                            <?= $view('partials/icon', ['name' => 'unlock', 'size' => 16]) ?>
                            <span>解除封禁</span>
                        </button>
                        <span class="ow-text-light" style="font-size:12.5px;margin-left:8px">
                            会同时清理该用户 IP 与邮箱上的封禁记录；若其处于禁言组，会恢复为默认注册用户组。
                        </span>
                    </form>
                <?php else: ?>
                    <?php if ($isSuper): ?>
                        <div role="alert" data-ow-variant="warning" style="margin-bottom:14px">
                            <?= $view('partials/icon', ['name' => 'alert', 'size' => 18]) ?>
                            <div>对方是超级管理员，只有超级管理员本人才能封禁其他超级管理员。</div>
                        </div>
                    <?php endif; ?>

                    <?php if (old_error('ban') !== ''): ?>
                        <div role="alert" data-ow-variant="danger" style="margin-bottom:14px">
                            <?= $view('partials/icon', ['name' => 'alert', 'size' => 18]) ?>
                            <div><?= e(old_error('ban')) ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?= e(url('/admin/users/' . $userId . '/ban')) ?>"
                          data-confirm="确定要封禁该用户吗？">
                        <?= csrf_field() ?>

                        <div class="form-grid">
                            <div data-ow-field>
                                <label for="ban-reason">封禁原因</label>
                                <input type="text" id="ban-reason" name="reason" maxlength="200"
                                       placeholder="会记录到操作日志中">
                            </div>

                            <div data-ow-field>
                                <label for="ban-days">封禁天数</label>
                                <input type="number" id="ban-days" name="days" min="0" max="3650" value="0">
                                <span data-ow-hint>填 0 表示永久封禁。</span>
                            </div>
                        </div>

                        <div data-ow-field>
                            <label>附加措施</label>
                            <label class="ow-hstack" style="gap:8px;margin-bottom:8px">
                                <input type="checkbox" name="ban_ip" value="1">
                                <span>
                                    <strong style="font-size:13.5px">同时封禁 IP</strong>
                                    <span class="ow-text-light" style="display:block;font-size:12.5px">
                                        使用其最后登录 IP（为空时取注册 IP），可防止换号重来。
                                    </span>
                                </span>
                            </label>
                            <label class="ow-hstack" style="gap:8px;margin-bottom:8px">
                                <input type="checkbox" name="ban_email" value="1">
                                <span>
                                    <strong style="font-size:13.5px">同时封禁邮箱</strong>
                                    <span class="ow-text-light" style="display:block;font-size:12.5px">
                                        <?= e((string)($profile['email'] ?? '')) ?>
                                    </span>
                                </span>
                            </label>
                            <label class="ow-hstack" style="gap:8px">
                                <input type="checkbox" name="mute" value="1">
                                <span>
                                    <strong style="font-size:13.5px">移入禁言组（只读）</strong>
                                    <span class="ow-text-light" style="display:block;font-size:12.5px">
                                        封禁到期后账号可登录，但仍需手动恢复用户组。
                                    </span>
                                </span>
                            </label>
                        </div>

                        <button type="submit" class="ow-button" data-ow-variant="danger">
                            <?= $view('partials/icon', ['name' => 'ban', 'size' => 16]) ?>
                            <span>执行封禁</span>
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
