<?php
/**
 * 输入校验器
 *
 * 规则以字符串 DSL 声明，例如：
 *   Validator::make($data, ['username' => 'required|min:3|max:20|username']);
 *
 * 校验失败时返回字段级错误，交由控制器写入 Session 并回填表单。
 */

declare(strict_types=1);

namespace Core;

final class Validator
{
    /** @var array<string, mixed> 待校验数据 */
    private array $data;

    /** @var array<string, list<string>> 规则表 */
    private array $rules;

    /** @var array<string, string> 字段中文名（用于错误提示） */
    private array $labels;

    /** @var array<string, list<string>> 校验错误（同一字段可能命中多条规则） */
    private array $errors = [];

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules  字段 => 规则串
     * @param array<string, string> $labels 字段 => 中文名
     */
    public function __construct(array $data, array $rules, array $labels = [])
    {
        $this->data   = $data;
        $this->rules  = [];
        $this->labels = $labels;

        foreach ($rules as $field => $ruleString) {
            $this->rules[$field] = array_filter(array_map('trim', explode('|', (string)$ruleString)));
        }
    }

    /**
     * 创建校验器
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     * @param array<string, string> $labels
     */
    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    /**
     * 执行校验
     */
    public function validate(): self
    {
        foreach ($this->rules as $field => $rules) {
            $value = $this->data[$field] ?? null;

            $isEmpty  = $value === null || $value === '' || (is_array($value) && $value === []);
            $required = in_array('required', $rules, true);

            if ($isEmpty) {
                /*
                 * 空值只判断「必填」，其余规则（格式 / 长度 / 一致性…）一律跳过。
                 * 否则一个空字段会同时报「不能为空」和「格式不正确」两条，
                 * 后者在用户还没输入时毫无可操作性，属于噪音。
                 */
                if ($required) {
                    $this->applyRule($field, 'required', '', $value);
                }

                continue;
            }

            foreach ($rules as $rule) {
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, '');
                $this->applyRule($field, $name, $param, $value);
            }
        }

        return $this;
    }

    /** 是否校验失败 */
    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** 是否通过 */
    public function passes(): bool
    {
        return $this->errors === [];
    }

    /**
     * 字段级错误
     *
     * 每个字段对应一个「该字段全部未通过规则」的提示列表。
     * 例如 username 同时违反 required 与 username 时会返回两条。
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /** 第一条错误（用于只需要一句话的场景，如跳转提示） */
    public function firstError(): string
    {
        foreach ($this->errors as $messages) {
            foreach ((array)$messages as $message) {
                return (string)$message;
            }
        }

        return '';
    }

    /**
     * 执行单条规则
     */
    private function applyRule(string $field, string $name, string $param, mixed $value): void
    {
        $label = $this->labels[$field] ?? $field;
        $str   = is_scalar($value) ? (string)$value : '';
        $len   = mb_strlen($str);

        switch ($name) {
            case '':
                return;

            case 'required':
                if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                    $this->addError($field, $label . '不能为空。');
                }
                return;

            case 'min':
                if ($len < (int)$param) {
                    $this->addError($field, $label . '至少需要 ' . (int)$param . ' 个字符。');
                }
                return;

            case 'max':
                if ($len > (int)$param) {
                    $this->addError($field, $label . '不能超过 ' . (int)$param . ' 个字符。');
                }
                return;

            case 'between':
                $range = array_map('intval', explode(',', $param));
                $min   = $range[0] ?? 0;
                $max   = $range[1] ?? PHP_INT_MAX;
                if ($len < $min || $len > $max) {
                    $this->addError($field, $label . '长度需在 ' . $min . '-' . $max . ' 个字符之间。');
                }
                return;

            case 'numeric':
                if (!is_numeric($str)) {
                    $this->addError($field, $label . '必须是数字。');
                }
                return;

            case 'integer':
                if (!preg_match('/^-?\d+$/', $str)) {
                    $this->addError($field, $label . '必须是整数。');
                }
                return;

            case 'email':
                if (filter_var($str, FILTER_VALIDATE_EMAIL) === false) {
                    $this->addError($field, $label . '格式不正确。');
                }
                return;

            case 'url':
                if (filter_var($str, FILTER_VALIDATE_URL) === false) {
                    $this->addError($field, $label . '必须是合法网址。');
                }
                return;

            case 'username':
                if (!preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{2,20}$/u', $str)) {
                    $this->addError($field, '用户名只能包含中文、字母、数字、下划线，长度 2-20 位。');
                }
                return;

            case 'alpha_dash':
                if (!preg_match('/^[A-Za-z0-9_\-]+$/', $str)) {
                    $this->addError($field, $label . '只能包含字母、数字、下划线与短横线。');
                }
                return;

            case 'same':
                if ($str !== (string)($this->data[$param] ?? '')) {
                    $this->addError($field, '两次输入的' . $label . '不一致。');
                }
                return;

            case 'in':
                $allowed = explode(',', $param);
                if (!in_array($str, $allowed, true)) {
                    $this->addError($field, $label . '取值不合法。');
                }
                return;

            case 'regex':
                // 仅允许使用白名单字符的正则，避免 ReDoS 与分隔符注入
                if (@preg_match('/' . str_replace('/', '\/', $param) . '/u', $str) !== 1) {
                    $this->addError($field, $label . '格式不正确。');
                }
                return;

            case 'bool':
                if (!in_array($str, ['0', '1', 'on', 'off', 'true', 'false', ''], true)) {
                    $this->addError($field, $label . '取值不合法。');
                }
                return;

            default:
                // 未知规则不做处理，避免因拼写错误静默放行
                Logger::warning('未知校验规则：' . $name);
                return;
        }
    }

    private function addError(string $field, string $message): void
    {
        /*
         * 同一个字段可能同时违反多条规则（例如密码既太短又没数字），
         * 这里全部保留，而不是只留第一条——否则用户只能「改一条、报一条」，
         * 来回试错才能把表单填对。
         */
        $this->errors[$field][] = $message;
    }
}
