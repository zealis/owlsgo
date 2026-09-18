<?php
/**
 * 日志
 *
 * 生产环境不要向浏览器输出任何堆栈，一切异常细节写入 storage/logs/。
 * 日志文件按天切分，并对重复信息做去重，避免刷日志把磁盘写满。
 */

declare(strict_types=1);

namespace Core;

final class Logger
{
    /** 日志级别 => 文件后缀 */
    private const LEVELS = ['debug', 'info', 'warning', 'error'];

    /** @var array<string, int> 本次请求内已记录过的消息指纹，用于去重 */
    private static array $seen = [];

    /** 单文件大小上限 5MB，超出后轮转 */
    private const MAX_SIZE = 5242880;

    public static function debug(string $message, array $context = []): void
    {
        if (App::debug()) {
            self::write('debug', $message, $context);
        }
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /**
     * 记录异常（含堆栈，仅落盘）
     */
    public static function exception(\Throwable $e, string $scope = ''): void
    {
        $context = [
            'exception' => get_class($e),
            'file'      => $e->getFile() . ':' . $e->getLine(),
            'trace'     => $e->getTraceAsString(),
            'path'      => Request::path(),
            'method'    => Request::method(),
            'ip'        => Request::ip(),
        ];

        self::write('error', ($scope !== '' ? '[' . $scope . '] ' : '') . $e->getMessage(), $context);
    }

    /**
     * 写入日志
     *
     * @param array<string, mixed> $context
     */
    public static function write(string $level, string $message, array $context = []): void
    {
        if (!in_array($level, self::LEVELS, true)) {
            $level = 'info';
        }

        // 同一请求内的重复消息只写一次
        $fingerprint = $level . '|' . md5($message . json_encode($context));
        if (isset(self::$seen[$fingerprint])) {
            return;
        }
        self::$seen[$fingerprint] = 1;

        $line = sprintf(
            "[%s] %s.%s %s %s\n",
            date('Y-m-d H:i:s'),
            $level,
            strtoupper($level),
            $message,
            $context === [] ? '' : json_encode(self::sanitize($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $file = self::filePath($level);

        try {
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            // 超过上限则轮转，避免单文件无限增长
            if (is_file($file) && filesize($file) > self::MAX_SIZE) {
                @rename($file, $file . '.' . date('YmdHis'));
            }

            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // 日志失败不得影响主流程
        }
    }

    /**
     * 脱敏：避免把密码、令牌写进日志
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function sanitize(array $context): array
    {
        $sensitive = ['password', 'password_confirm', 'token', '_token', 'secret', 'csrf', 'cookie', 'authorization'];

        foreach ($context as $key => $value) {
            $lowerKey = strtolower((string)$key);

            foreach ($sensitive as $needle) {
                if (str_contains($lowerKey, $needle)) {
                    $context[$key] = '[已脱敏]';
                    continue 2;
                }
            }

            if (is_array($value)) {
                $context[$key] = self::sanitize($value);
            } elseif (is_string($value) && mb_strlen($value) > 500) {
                $context[$key] = mb_substr($value, 0, 500) . '…';
            }
        }

        return $context;
    }

    /** 日志文件路径 */
    private static function filePath(string $level): string
    {
        return APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
            . 'logs' . DIRECTORY_SEPARATOR . $level . '-' . date('Y-m-d') . '.log';
    }

    /**
     * 清理超过指定天数的日志（计划任务调用）
     */
    public static function prune(int $days = 14): int
    {
        $dir = APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($dir)) {
            return 0;
        }

        $threshold = time() - max(1, $days) * 86400;
        $removed   = 0;

        foreach ((array)glob($dir . DIRECTORY_SEPARATOR . '*.log*') as $file) {
            if (is_file($file) && filemtime($file) < $threshold) {
                @unlink($file);
                $removed++;
            }
        }

        return $removed;
    }
}
