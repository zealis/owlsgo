<?php
/**
 * 用户中心头部 —— 名片 + 导航（个人主页 / 发表的帖子 / 发表的评论 / 我的收藏 / 账号设置）
 *
 * 传入：$profile（用户行）、$active（当前导航项：home|threads|posts|favorites|settings）
 *
 * 排版意图（2026-09-19 按需求调整）：名片与导航**结合成一个头部块** ——
 * 贴合处各自收平圆角、中间只留一道细缝，视觉上是「名片下面挂着一排页签」；
 * 两者外沿各自保留大圆角，所以整体仍是一个圆角卡片轮廓。
 * 具体数值见 theme.css 的「.profile-head」一段，改那里就能调缝宽与圆角。
 */

declare(strict_types=1);

$headProfile = is_array($profile ?? null) ? $profile : [];
$headActive  = (string)($active ?? 'home');
?>
<div class="profile-head">
    <?= $view('partials/profile-hero', ['profile' => $headProfile]) ?>
    <?= $view('partials/user-nav', ['userNavProfile' => $headProfile, 'userNavActive' => $headActive]) ?>
</div>
