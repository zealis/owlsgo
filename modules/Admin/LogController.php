<?php
/**
 * 后台：操作日志
 *
 * 只读页面：支持按动作与关键词筛选、分页浏览，以及把当前筛选结果导出为 CSV。
 * 导出使用流式输出并限制最大条数，避免一次拉爆内存。
 */

declare(strict_types=1);

namespace Modules\Admin;

use Core\Auth;
use Core\Paginator;
use Core\Request;
use Core\Response;
use Core\Router;
use Modules\User\UserModel;

final class LogController extends AdminBaseController
{
    /** 单次导出上限 */
    private const EXPORT_LIMIT = 5000;

    /** 系统日志单次最多展示的行数 */
    private const TAIL_LINES = 400;

    /* ------------------------------------------------------------------ */
    /*  系统日志（storage/logs 下的文件日志）                                */
    /* ------------------------------------------------------------------ */

    /**
     * 系统日志
     *
     * 和上面「操作日志」的区别：
     *   操作日志存数据库 —— 谁在什么时候做了什么（可审计）；
     *   系统日志是 storage/logs/ 下的文件 —— PHP 报错、异常堆栈、调试信息。
     *   生产环境（debug = false）不会把错误细节显示给用户，但会写进这里，
     *   所以排查线上问题时看的就是它。
     *
     * @param array<string, string> $params
     */
    public function system(array $params): string
    {
        $files = $this->logFiles();

        // 默认打开最近写过的那个文件
        $current = Request::string('file', '', 64);

        if ($this->safeLogName($current) === null || !isset($files[$current])) {
            $current = (string)(array_key_first($files) ?? '');
        }

        $lines     = [];
        $truncated = false;

        if ($current !== '') {
            $all       = $this->tailLines($this->logPath($current), self::TAIL_LINES + 1);
            $truncated = count($all) > self::TAIL_LINES;
            $lines     = array_slice($all, -self::TAIL_LINES);
        }

        return $this->adminView('admin/logs-system', [
            'pageTitle'    => '系统日志 - ' . (string)setting('site_name'),
            'adminTitle'   => '系统日志',
            'files'        => $files,
            'current'      => $current,
            'lines'        => $lines,
            'truncated'    => $truncated,
            'maxLines'     => self::TAIL_LINES,
            'debugEnabled' => \Core\App::debug(),
        ]);
    }

    /**
     * 清空指定的日志文件
     *
     * 只删单个文件，不做「清空全部」—— 批量删除一旦参数被篡改后果不可控。
     *
     * @param array<string, string> $params
     */
    public function clearSystemLog(array $params): never
    {
        $name = Request::string('file', '', 64);
        $back = Router::url('/admin/logs/system');

        if ($this->safeLogName($name) === null || !is_file($this->logPath($name))) {
            $this->backWithErrors(['file' => '日志文件不存在或名称不合法。'], $back);
        }

        if (!@unlink($this->logPath($name))) {
            $this->backWithErrors(['file' => '删除失败，请检查 storage/logs 目录的写权限。'], $back);
        }

        LogModel::record(Auth::id(), 'system.log.clear', 'log:' . $name, '清空系统日志文件');

        $this->redirectWith($back, '日志文件 ' . $name . ' 已清空。');
    }

    /**
     * 列出日志文件，最近写过的在前
     *
     * @return array<string, array{size:int, mtime:int}>
     */
    private function logFiles(): array
    {
        $dir = APP_ROOT . '/storage/logs';

        if (!is_dir($dir)) {
            return [];
        }

        $result = [];

        foreach ((array)glob($dir . '/*.log') as $file) {
            $name = basename((string)$file);

            if ($this->safeLogName($name) === null) {
                continue;
            }

            $result[$name] = [
                'size'  => (int)@filesize($file),
                'mtime' => (int)@filemtime($file),
            ];
        }

        uasort($result, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        return $result;
    }

    /**
     * 日志文件名白名单校验
     *
     * 文件名来自 URL，只接受 {level}-YYYY-MM-DD.log 这一种形态。
     * 少了这道校验，`?file=../../config/app.php` 就能读到任意文件。
     */
    private function safeLogName(string $name): ?string
    {
        return preg_match('/^[a-z]{4,8}-\d{4}-\d{2}-\d{2}\.log$/', $name) === 1 ? $name : null;
    }

    /** 日志文件的绝对路径（调用前必须先过 safeLogName） */
    private function logPath(string $name): string
    {
        return APP_ROOT . '/storage/logs/' . $name;
    }

    /**
     * 读取文件末尾若干行
     *
     * 日志可能涨到很大，不能整个读进内存：小文件直接读，
     * 大文件从尾部按块倒着取，取够行数就停。
     *
     * @return list<string>
     */
    private function tailLines(string $path, int $maxLines): array
    {
        if (!is_file($path)) {
            return [];
        }

        if ((int)@filesize($path) <= 524288) {
            $lines = @file($path, FILE_IGNORE_NEW_LINES);

            return is_array($lines) ? array_slice($lines, -$maxLines) : [];
        }

        $fp = @fopen($path, 'rb');

        if ($fp === false) {
            return [];
        }

        $chunk  = 16384;
        $pos    = (int)@filesize($path);
        $buffer = '';

        while ($pos > 0 && substr_count($buffer, "\n") <= $maxLines) {
            $read = (int)min($chunk, $pos);
            $pos -= $read;

            fseek($fp, $pos);
            $buffer = (string)fread($fp, $read) . $buffer;
        }

        fclose($fp);

        return array_slice(explode("\n", $buffer), -$maxLines);
    }

    /**
     * 日志列表
     *
     * @param array<string, string> $params
     */
    public function index(array $params): string
    {
        $action  = Request::string('action', '', 60);
        $keyword = Request::string('q', '', 60);
        $page    = $this->currentPage();

        $result = LogModel::paginate($action, $keyword, $page, 30);

        // 批量补齐操作者用户名
        $userIds = array_map(static fn (array $row): int => (int)$row['user_id'], $result['items']);
        $users   = UserModel::mapByIds($userIds);

        foreach ($result['items'] as &$item) {
            $user = $users[(int)$item['user_id']] ?? null;

            $item['username']   = (string)($user['username'] ?? '系统');
            $item['avatar']     = $user;
            $item['time_text']  = (int)$item['created_at'] > 0 ? date('Y-m-d H:i:s', (int)$item['created_at']) : '—';
        }
        unset($item);

        $query = array_filter([
            'action' => $action,
            'q'      => $keyword,
        ]);

        return $this->adminView('admin/logs', [
            'pageTitle'  => '操作日志 - ' . (string)setting('site_name'),
            'adminTitle' => '操作日志',
            'result'     => $result,
            'action'     => $action,
            'keyword'    => $keyword,
            'actions'    => LogModel::actions(),
            'total'      => LogModel::totalCount(),
            'pagination' => Paginator::render($result, '/admin/logs', $query),
            'exportUrl'  => Router::url('/admin/logs/export', $query),
        ]);
    }

    /**
     * 导出 CSV
     *
     * @param array<string, string> $params
     */
    public function export(array $params): never
    {
        $action  = Request::string('action', '', 60);
        $keyword = Request::string('q', '', 60);

        $rows = LogModel::exportRows($action, $keyword, self::EXPORT_LIMIT);

        $filename = 'owlsgo-logs-' . date('Ymd-His') . '.csv';

        // 清空缓冲，避免 CSV 内容被前面任何输出污染
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        Response::header('Content-Type', 'text/csv; charset=UTF-8');
        Response::header('X-Content-Type-Options', 'nosniff');
        Response::header(
            'Content-Disposition',
            'attachment; filename="' . $filename . '"; '
            . "filename*=UTF-8''" . rawurlencode('操作日志-' . date('Ymd-His') . '.csv')
        );

        Response::sendHeaders();

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            exit;
        }

        // UTF-8 BOM：让 Excel 正确识别中文
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, ['时间', '操作者ID', '动作', '对象', '详情', 'IP']);

        foreach ($rows as $row) {
            fputcsv($out, [
                (int)$row['created_at'] > 0 ? date('Y-m-d H:i:s', (int)$row['created_at']) : '',
                (int)$row['user_id'],
                $this->csvSafe((string)$row['action']),
                $this->csvSafe((string)$row['target']),
                $this->csvSafe((string)$row['detail']),
                (string)$row['ip'],
            ]);
        }

        fclose($out);

        $this->audit('log.export', 'logs', '导出操作日志 ' . count($rows) . ' 条（操作人：' . Auth::id() . '）');

        exit;
    }

    /* ------------------------------------------------------------------ */
    /*  内部辅助                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * 防止 CSV 公式注入
     *
     * 以 = + - @ 开头的单元格会被 Excel 当作公式执行，这里统一加上前导单引号。
     */
    private function csvSafe(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }
}
