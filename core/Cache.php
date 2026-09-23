<?php
/**
 * 两级缓存
 *
 * 第一级：请求内静态数组。站点设置、版块、用户组等「一次请求要读很多次」的数据，
 *         靠这一级把 N 次查询压缩成 1 次。
 * 第二级：文件缓存（storage/cache/）。跨请求复用，用于降低高负载下的数据库压力。
 *
 * 零依赖：不引入 Redis/Memcached；如需更快的缓存，可自行扩展 Cache::driver。
 */

declare(strict_types=1);

namespace Core;

final class Cache
{
    /** @var array<string, mixed> 请求内缓存 */
    private static array $memo = [];

    /** @var array<string, bool> 记录哪些键在本次请求内已被删除（防止文件缓存回填） */
    private static array $tombstone = [];

    /**
     * 读取缓存
     *
     * @param mixed $default 未命中时的默认值
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        if (isset(self::$tombstone[$key])) {
            return $default;
        }

        if (!self::fileEnabled()) {
            return $default;
        }

        $file = self::filePath($key);
        if (!is_file($file)) {
            return $default;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return $default;
        }

        $payload = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($payload) || !array_key_exists('expires', $payload)) {
            @unlink($file);

            return $default;
        }

        if ((int)$payload['expires'] !== 0 && (int)$payload['expires'] < time()) {
            @unlink($file);

            return $default;
        }

        self::$memo[$key] = $payload['value'] ?? null;

        return self::$memo[$key];
    }

    /**
     * 写入缓存
     *
     * @param int $ttl 秒；0 表示仅本次请求内有效
     */
    public static function set(string $key, mixed $value, int $ttl = 0): void
    {
        self::$memo[$key] = $value;
        unset(self::$tombstone[$key]);

        if (!self::fileEnabled() || $ttl <= 0) {
            return;
        }

        $file = self::filePath($key);
        $dir  = dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        $payload = serialize([
            'expires' => time() + $ttl,
            'value'   => $value,
        ]);

        // 原子写入：先写临时文件再重命名，避免并发读到半截内容
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            @rename($tmp, $file);
        } else {
            @unlink($tmp);
        }
    }

    /**
     * 读取缓存，未命中时执行回调并写入
     *
     * @param callable():mixed $resolver
     */
    public static function remember(string $key, int $ttl, callable $resolver): mixed
    {
        $value = self::get($key, '__owlsgo_cache_miss__');

        if ($value !== '__owlsgo_cache_miss__') {
            return $value;
        }

        $value = $resolver();

        /*
         * 事务中不写缓存：此刻读到的很可能是**尚未提交**的数据，
         * 一旦事务回滚，缓存里就留下一份库里根本不存在的「脏数据」，
         * 而且没人会去清它（回滚路径不知道写过哪些键）。
         * 所以事务内只返回结果、不落缓存 —— 提交后由后续请求正常建立缓存。
         */
        if (!Database::inTransaction()) {
            self::set($key, $value, $ttl);
        }

        return $value;
    }

    /**
     * 删除缓存
     *
     * **事务中调用会延后到提交后执行**（见类文件顶部的说明）：
     * 事务回滚时数据库并没有变，缓存仍然是对的，没必要白失效一次；
     * 只有真正提交了，才需要让缓存跟着更新。
     */
    public static function forget(string $key): void
    {
        if (self::deferToCommit()) {
            self::$deferredForget[$key] = true;

            return;
        }

        self::doForget($key);
    }

    /** 真正执行单键失效（内部使用，不做事务判断） */
    private static function doForget(string $key): void
    {
        unset(self::$memo[$key]);
        self::$tombstone[$key] = true;

        $file = self::filePath($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * 按前缀批量失效（例如所有以 "forum:" 开头的缓存）
     */
    public static function forgetPrefix(string $prefix): void
    {
        if (self::deferToCommit()) {
            self::$deferredPrefix[$prefix] = true;

            return;
        }

        self::doForgetPrefix($prefix);
    }

    /** 真正执行前缀失效（内部使用） */
    private static function doForgetPrefix(string $prefix): void
    {
        foreach (array_keys(self::$memo) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset(self::$memo[$key]);
            }
        }

        self::$tombstone[$prefix . '*'] = true;

        if (!self::fileEnabled()) {
            return;
        }

        $dir = self::cacheDir();
        if (!is_dir($dir)) {
            return;
        }

        foreach ((array)glob($dir . DIRECTORY_SEPARATOR . self::safeKey($prefix) . '*') as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * 清空全部文件缓存（事务中调用会延后到提交后执行）
     */
    public static function flush(): int
    {
        if (self::deferToCommit()) {
            self::$deferredFlush = true;

            return 0;
        }

        return self::doFlush();
    }

    /** 真正执行全量清空（内部使用） */
    private static function doFlush(): int
    {
        self::$memo      = [];
        self::$tombstone = [];

        $dir = self::cacheDir();
        if (!is_dir($dir)) {
            return 0;
        }

        $removed = 0;
        foreach ((array)glob($dir . DIRECTORY_SEPARATOR . '*.cache') as $file) {
            if (is_file($file)) {
                @unlink($file);
                $removed++;
            }
        }

        return $removed;
    }

    // ------------------------------------------------------------------
    // 事务感知：数据改完（提交成功）之后再失效缓存
    // ------------------------------------------------------------------

    /** 事务内累积的待失效键 / 前缀 / 是否需要全清 */
    private static array $deferredForget = [];
    private static array $deferredPrefix = [];
    private static bool $deferredFlush   = false;

    /** 正在执行延后任务（避免递归再入队） */
    private static bool $committing = false;

    /**
     * 是否应当把这次失效延后到事务提交之后
     *
     * 判断依据：当前在事务里，且不是在「执行延后任务」的过程中。
     */
    private static function deferToCommit(): bool
    {
        return !self::$committing && Database::inTransaction();
    }

    /**
     * 事务提交成功：执行事务里累积的缓存失效
     *
     * 由 Core\Database::transaction() 在最外层 commit 之后调用。
     */
    public static function commitDeferred(): void
    {
        if (self::$deferredFlush) {
            self::$deferredFlush = false;
            self::$deferredForget = [];
            self::$deferredPrefix = [];
            self::$committing = true;
            self::doFlush();
            self::$committing = false;

            return;
        }

        if (self::$deferredForget === [] && self::$deferredPrefix === []) {
            return;
        }

        $keys     = array_keys(self::$deferredForget);
        $prefixes = array_keys(self::$deferredPrefix);
        self::$deferredForget = [];
        self::$deferredPrefix = [];

        self::$committing = true;
        foreach ($keys as $key) {
            self::doForget($key);
        }
        foreach ($prefixes as $prefix) {
            self::doForgetPrefix($prefix);
        }
        self::$committing = false;
    }

    /** 事务回滚：丢弃累积的失效（库没变，缓存也不用动） */
    public static function discardDeferred(): void
    {
        self::$deferredForget = [];
        self::$deferredPrefix = [];
        self::$deferredFlush  = false;
    }

    /** 文件缓存是否启用 */
    private static function fileEnabled(): bool
    {
        return config('app.cache_driver', 'file') === 'file'
            && (int)config('app.cache_ttl', 0) > 0;
    }

    /** 缓存目录 */
    private static function cacheDir(): string
    {
        return APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
    }

    /** 缓存键 => 文件名（只使用安全字符，防止路径穿越） */
    private static function safeKey(string $key): string
    {
        return preg_replace('/[^A-Za-z0-9_.\-]/', '_', $key) ?? 'cache';
    }

    /** 缓存文件路径 */
    private static function filePath(string $key): string
    {
        return self::cacheDir() . DIRECTORY_SEPARATOR . self::safeKey($key) . '.cache';
    }
}
