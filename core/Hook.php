<?php
/**
 * 插件钩子注册表
 *
 * 沙箱约束：插件只能通过 Hook::on / Hook::filter 注入逻辑，
 * 不允许直接修改核心全局状态。核心在合适的时机调用 Hook::filter / Hook::action，
 * 插件无法「抢占」未声明的位置，因此可预测性高、便于排查。
 *
 * 两类钩子：
 *  - filter：有返回值的内容过滤，例如帖子正文渲染前的处理
 *  - action：只关心「发生了某件事」，例如发帖成功后发通知
 */

declare(strict_types=1);

namespace Core;

final class Hook
{
    /** @var array<string, array<int, list<callable>>> 钩子名 => 优先级 => 回调列表 */
    private static array $listeners = [];

    /**
     * 核心钩子总表：与代码中真实触发点严格一一对应
     *
     * 结构：钩子名 => ['type' => 'filter'|'action', 'desc' => 说明, 'context' => 上下文键]
     *  - filter：回调签名 fn (mixed $value, array $context): mixed，返回值沿链传递
     *  - action：回调签名 fn (array $context): void，返回值被忽略
     *
     * 维护约定（重要）：新增钩子必须同时「在本表登记」且「在核心代码中真实触发」。
     * 只登记不触发的钩子会让插件写出永远不执行的死代码，属严重缺陷。
     *
     * @var array<string, array{type:string, desc:string, context:string}>
     */
    public const DEFINED = [
        /* ---------------- 生命周期 ---------------- */
        'app_boot'              => ['type' => 'action', 'desc' => '应用启动完成（会话、设置、插件、路由均已就绪）', 'context' => 'app'],
        'app_before_dispatch'   => ['type' => 'action', 'desc' => '路由分发前，可用于全局拦截', 'context' => 'path, route'],
        'app_after_dispatch'    => ['type' => 'action', 'desc' => '路由分发后', 'context' => 'path, route'],
        'plugin_loaded'         => ['type' => 'action', 'desc' => '单个插件加载完成', 'context' => 'plugin'],

        /* ---------------- 资源与导航 ---------------- */
        'head_assets'           => ['type' => 'filter', 'desc' => '在 <head> 内追加 HTML（如 <link>），返回字符串', 'context' => '—'],
        'footer_assets'         => ['type' => 'filter', 'desc' => '在 </body> 前追加 HTML（如 <script>），返回字符串', 'context' => '—'],
        'nav_links'             => ['type' => 'filter', 'desc' => '前台导航附加链接数组，可增删改', 'context' => 'user'],
        'admin_nav_links'       => ['type' => 'filter', 'desc' => '后台左侧导航链接数组，可增删改', 'context' => 'user'],
        'user_profile_tabs'     => ['type' => 'filter', 'desc' => '用户中心标签页数组', 'context' => 'profile, active'],
        'thread_view_actions'   => ['type' => 'filter', 'desc' => '主题详情页操作区 HTML，可追加按钮', 'context' => 'thread, forum, user'],

        /* ---------------- 内容与列表 ---------------- */
        'content_render'        => ['type' => 'filter', 'desc' => '正文「渲染前的原文」过滤（入库前）', 'context' => 'user, forum, thread'],
        'content_rendered'      => ['type' => 'filter', 'desc' => '正文「渲染后的 HTML」过滤（输出时）', 'context' => 'raw'],
        'forum_list'            => ['type' => 'filter', 'desc' => '首页版块列表过滤', 'context' => 'user'],
        'thread_list'           => ['type' => 'filter', 'desc' => '版块内主题列表过滤', 'context' => 'forum'],
        'post_list'             => ['type' => 'filter', 'desc' => '主题内回帖列表过滤', 'context' => 'thread'],

        /* ---------------- 主题 ---------------- */
        'before_thread_create'  => ['type' => 'action', 'desc' => '发表主题前，可用 App::abort() 或 Response::redirect() 拦截', 'context' => 'user, forum, title, content'],
        'after_thread_create'   => ['type' => 'action', 'desc' => '发表主题后', 'context' => 'user, forum, thread_id, post_id'],
        'after_thread_update'   => ['type' => 'action', 'desc' => '编辑主题后', 'context' => 'thread_id, user'],
        'after_thread_delete'   => ['type' => 'action', 'desc' => '删除主题后', 'context' => 'thread_id, user'],

        /* ---------------- 回帖 ---------------- */
        'before_post_create'    => ['type' => 'action', 'desc' => '发表回复前，可抛异常阻止', 'context' => 'user, thread, forum, content'],
        'after_post_create'     => ['type' => 'action', 'desc' => '发表回复后（待审核的回复不触发）', 'context' => 'user, thread_id, post_id, floor'],
        'after_post_delete'     => ['type' => 'action', 'desc' => '删除回复后', 'context' => 'post_id, user'],

        /* ---------------- 用户 ---------------- */
        'before_user_register'  => ['type' => 'action', 'desc' => '注册前，可用 App::abort() 或 Response::redirect() 拦截（如邀请码校验）', 'context' => 'username, email'],
        'after_user_register'   => ['type' => 'action', 'desc' => '注册后（此时已自动登录）', 'context' => 'user_id, username'],
        'after_user_login'      => ['type' => 'action', 'desc' => '登录成功后', 'context' => 'user_id'],
        'after_user_logout'     => ['type' => 'action', 'desc' => '退出登录后', 'context' => 'user_id'],
        'after_profile_update'  => ['type' => 'action', 'desc' => '修改资料后', 'context' => 'user_id'],
        'after_password_change' => ['type' => 'action', 'desc' => '修改密码后', 'context' => 'user_id'],
        'after_avatar_upload'   => ['type' => 'action', 'desc' => '上传头像后', 'context' => 'user_id, path'],
    ];

    /**
     * 注册监听器
     *
     * @param int $priority 数值越小越先执行
     */
    public static function on(string $name, callable $callback, int $priority = 10): void
    {
        $name = self::normalize($name);

        self::$listeners[$name][$priority][] = $callback;

        // 保证同优先级保持注册顺序
        ksort(self::$listeners[$name]);
    }

    /**
     * 触发内容过滤钩子，返回被链式处理后的值
     *
     * 异常策略（有意为之，不要随意改动）：
     *  - HttpException 直接向上冒泡：这是插件「主动拦截请求」的唯一受支持方式，
     *    插件通过 App::abort(403, '原因') 或 Response::redirect() 中断流程时，
     *    必须交给 App 去渲染错误页 / 执行跳转，不能被吞掉。
     *  - 其它异常一律隔离并写日志：插件自身的缺陷不允许拖垮整站。
     *
     * @param array<string, mixed> $context
     */
    public static function filter(string $name, mixed $value, array $context = []): mixed
    {
        $name = self::normalize($name);

        if (empty(self::$listeners[$name])) {
            return $value;
        }

        foreach (self::$listeners[$name] as $callbacks) {
            foreach ($callbacks as $callback) {
                try {
                    $value = $callback($value, $context);
                } catch (HttpException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    self::handleCallbackError($e, $name);
                }
            }
        }

        return $value;
    }

    /**
     * 触发动作钩子（忽略返回值）
     *
     * 与 filter 的关键区别（务必区分，否则回调会因签名不匹配而静默失效）：
     *   - filter 回调签名：function (mixed $value, array $context): mixed
     *   - action 回调签名：function (array $context): void
     *
     * 这里刻意不复用 filter()：早期实现把 action 转成「值为 null 的 filter」，
     * 于是按约定声明的单参数回调收到 null 会抛 TypeError，再被异常隔离吞掉，
     * 表现为「插件注册了钩子却毫无反应」，极难排查。
     *
     * @param array<string, mixed> $context
     */
    public static function action(string $name, array $context = []): void
    {
        $name = self::normalize($name);

        if (empty(self::$listeners[$name])) {
            return;
        }

        foreach (self::$listeners[$name] as $callbacks) {
            foreach ($callbacks as $callback) {
                try {
                    $callback($context);
                } catch (HttpException $e) {
                    // 拦截类钩子（before_*）依赖 HttpException 中断主流程
                    throw $e;
                } catch (\Throwable $e) {
                    self::handleCallbackError($e, $name);
                }
            }
        }
    }

    /**
     * 回调异常处理策略
     *
     * 默认隔离并写日志：插件缺陷不允许拖垮整站。
     * 但 debug 模式下直接抛出 —— 否则签名写错、方法名写错这类问题
     * 只会表现为「钩子静默不生效」，在开发期几乎无法定位。
     */
    private static function handleCallbackError(\Throwable $e, string $hookName): void
    {
        if (\Core\App::debug()) {
            throw $e;
        }

        Logger::exception($e, 'hook:' . $hookName);
    }

    /** 指定钩子是否有监听者 */
    public static function has(string $name): bool
    {
        return !empty(self::$listeners[self::normalize($name)]);
    }

    /** 所有已注册的钩子名 */
    public static function registered(): array
    {
        return array_keys(self::$listeners);
    }

    /** 清空监听器（插件重载时使用） */
    public static function clear(?string $name = null): void
    {
        if ($name === null) {
            self::$listeners = [];

            return;
        }

        unset(self::$listeners[self::normalize($name)]);
    }

    /** 钩子名规范化，防止拼写差异导致静默失效 */
    private static function normalize(string $name): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?? $name);
    }
}
