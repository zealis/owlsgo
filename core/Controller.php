<?php
/**
 * 控制器基类
 *
 * 只提供「输出」与「交互反馈」方面的便捷方法；业务逻辑请下沉到 Model。
 * 严禁在控制器中直接书写 SQL。
 */

declare(strict_types=1);

namespace Core;

abstract class Controller
{
    /**
     * 渲染页面
     *
     * @param string               $template 形如 forum/index
     * @param array<string, mixed> $data
     */
    protected function view(string $template, array $data = [], ?string $layout = null): string
    {
        return View::render($template, $data, $layout);
    }

    /**
     * 输出 JSON（AJAX 响应统一格式）
     *
     * @param array<string, mixed> $data
     */
    protected function json(array $data, int $code = 200): never
    {
        Response::json(array_merge(['ok' => $code < 400], $data), $code);
    }

    /** 成功响应 */
    protected function success(string $message = '操作成功。', array $extra = []): never
    {
        $this->json(array_merge(['message' => $message], $extra));
    }

    /** 失败响应 */
    protected function fail(string $message, int $code = 400, array $extra = []): never
    {
        $this->json(array_merge(['ok' => false, 'message' => $message], $extra), $code);
    }

    /**
     * 写入一次性提示并跳转
     */
    protected function redirectWith(string $url, string $message, string $type = 'success'): never
    {
        Session::setFlash('message', $message);
        Session::setFlash('type', $type);

        Response::redirect($url);
    }

    /**
     * 写入表单字段错误、回填输入并跳回表单
     *
     * 双轨反馈，与 flash.php 的样式约定对齐：
     *  - AJAX 表单（data-ajax）：校验错误以 JSON 返回，由前端 notify() 弹出提示。
     *    不写会话闪存 —— 页面不会刷新，写了也读不到，还会漏到下一次整页跳转里；
     *  - 普通整页提交：字段级错误写入会话，由 partials/flash.php 内联展示，
     *    供用户逐项对照修改（无 JS 时的兜底路径，必须保留）。
     */
    protected function backWithErrors(array $errors, string $fallbackUrl = '/'): never
    {
        if (Request::wantsJson()) {
            $messages = [];

            foreach ($errors as $list) {
                foreach (is_array($list) ? $list : [$list] as $message) {
                    $message = (string)$message;

                    if ($message !== '') {
                        $messages[] = $message;
                    }
                }
            }

            $this->json([
                'ok'      => false,
                'message' => $messages === [] ? '提交内容有误，请检查后重试。' : implode(' ', $messages),
                'errors'  => $errors,
            ], 422);
        }

        Session::setErrors($errors);
        Session::flashInput(Request::allPost());

        $referer = Request::referer();

        Response::redirect($referer !== '' ? $referer : $fallbackUrl);
    }

    /**
     * 处理「AJAX 与普通表单」两种提交方式的分支
     *
     * 返回 true 表示请求已被处理（已输出 JSON），调用方应 return。
     */
    protected function respond(array $ok, string $redirectUrl, string $failFallback): void
    {
        $isOk = (bool)($ok['ok'] ?? false);

        if (Request::wantsJson()) {
            $this->json([
                'ok'       => $isOk,
                'message'  => (string)($ok['message'] ?? ''),
                'redirect' => $isOk ? $redirectUrl : '',
            ], $isOk ? 200 : 400);
        }

        if ($isOk) {
            $this->redirectWith($redirectUrl, (string)($ok['message'] ?? '操作成功。'));
        }

        Session::setFlash('message', (string)($ok['message'] ?? '操作失败。'));
        Session::setFlash('type', 'error');

        Response::redirect($failFallback);
    }

    /** 当前登录用户（未登录直接 401/跳转） */
    protected function requireLogin(): array
    {
        return Auth::requireLogin();
    }

    /**
     * 要求权限
     *
     * @param array<string, mixed>|null $forum
     */
    protected function requirePermission(string $permission, ?array $forum = null, string $message = ''): array
    {
        return Auth::requirePermission($permission, $forum, $message);
    }

    /**
     * 校验输入
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     * @param array<string, string> $labels
     */
    protected function validate(array $data, array $rules, array $labels = []): Validator
    {
        return Validator::make($data, $rules, $labels)->validate();
    }

    /** 限流检查，超限直接中断请求（文案带「还要等多久」，响应带 Retry-After 头） */
    protected function throttle(string $action, string $key = ''): void
    {
        $wait = Security::rateLimitRetryAfter($action, $key);
        if ($wait === 0) {
            return;
        }

        $waitText = $wait >= 60
            ? (int)ceil($wait / 60) . ' 分钟'
            : $wait . ' 秒';
        $message = '操作过于频繁，请 ' . $waitText . '后再试。';

        throw new HttpException(429, $message, ['Retry-After' => (string)$wait]);
    }

    /**
     * 当前分页页码
     *
     * 注意：本方法原名 page()，与「路由动作用 page()」的写法冲突会触发
     * PHP 致命错误（Declaration must be compatible），故改名 currentPage()。
     */
    protected function currentPage(): int
    {
        return Paginator::page(Request::int('page', 1));
    }

    /** 每页条数 */
    protected function perPage(string $key = 'per_page'): int
    {
        $value = Request::int($key, (int)config('app.per_page', 20));

        return max(5, min(100, $value));
    }
}
