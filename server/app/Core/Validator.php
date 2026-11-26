<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Small rule-based validator with Arabic messages.
 * Rules: required, nullable, string, int, numeric, bool, email, url, min:n, max:n, between:a,b,
 * in:a,b, regex:/x/, date, year, hex, slug, array, confirmed, unique:table,col[,exceptId], exists:table,col
 * In partial mode (PUT/PATCH) absent fields are skipped entirely.
 */
final class Validator
{
    public static function validate(array $data, array $rules, array $labels = [], bool $partial = false): array
    {
        $errors = [];
        $clean = [];
        foreach ($rules as $field => $spec) {
            $list = is_array($spec) ? $spec : explode('|', $spec);
            $label = $labels[$field] ?? $field;
            if ($partial && !array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $isBool = in_array('bool', $list, true);
            $empty = $value === null || $value === '' || $value === [];
            if ($empty) {
                if (in_array('required', $list, true)) {
                    $errors[$field] = "حقل «{$label}» مطلوب";
                } elseif ($isBool) {
                    $clean[$field] = false;
                } elseif (array_key_exists($field, $data) || !$partial) {
                    $clean[$field] = in_array('array', $list, true) ? [] : null;
                }
                continue;
            }
            $error = null;
            $numeric = in_array('int', $list, true) || in_array('numeric', $list, true) || in_array('year', $list, true);
            foreach ($list as $rule) {
                [$name, $arg] = array_pad(explode(':', (string) $rule, 2), 2, null);
                $error = self::check($name, $arg, $value, $data, $field, $label, $numeric);
                if ($error !== null) {
                    break;
                }
            }
            if ($error !== null) {
                $errors[$field] = $error;
                continue;
            }
            $clean[$field] = self::cast($list, $value);
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return $clean;
    }

    private static function cast(array $rules, mixed $value): mixed
    {
        if (in_array('int', $rules, true) || in_array('year', $rules, true)) {
            return (int) $value;
        }
        if (in_array('numeric', $rules, true)) {
            return (float) $value;
        }
        if (in_array('bool', $rules, true)) {
            return (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        if (in_array('email', $rules, true)) {
            return mb_strtolower((string) $value);
        }
        return $value;
    }

    private static function check(string $rule, ?string $arg, mixed $v, array $data, string $field, string $label, bool $numeric): ?string
    {
        $len = static fn ($x) => is_array($x) ? count($x) : mb_strlen((string) $x);
        switch ($rule) {
            case 'required': case 'nullable': case 'sometimes':
                return null;
            case 'string':
                return is_scalar($v) ? null : "«{$label}» يجب أن يكون نصاً";
            case 'int':
                return filter_var($v, FILTER_VALIDATE_INT) !== false ? null : "«{$label}» يجب أن يكون رقماً صحيحاً";
            case 'numeric':
                return is_numeric($v) ? null : "«{$label}» يجب أن يكون رقماً";
            case 'bool':
                return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null || is_bool($v) ? null : "«{$label}» قيمة غير صحيحة";
            case 'email':
                return filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'البريد الإلكتروني غير صحيح';
            case 'url':
                return (filter_var($v, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', (string) $v)) ? null : "«{$label}» يجب أن يكون رابطاً يبدأ بـ http أو https";
            case 'min':
                if ($numeric) {
                    return (float) $v >= (float) $arg ? null : "«{$label}» يجب ألا يقل عن {$arg}";
                }
                return $len($v) >= (int) $arg ? null : "«{$label}» يجب ألا يقل عن {$arg} أحرف";
            case 'max':
                if ($numeric) {
                    return (float) $v <= (float) $arg ? null : "«{$label}» يجب ألا يزيد عن {$arg}";
                }
                return $len($v) <= (int) $arg ? null : "«{$label}» يجب ألا يزيد عن {$arg} حرفاً";
            case 'between':
                [$a, $b] = explode(',', (string) $arg);
                return (is_numeric($v) && $v >= $a && $v <= $b) ? null : "«{$label}» يجب أن يكون بين {$a} و {$b}";
            case 'in':
                return in_array((string) $v, explode(',', (string) $arg), true) ? null : "«{$label}» قيمة غير مسموحة";
            case 'regex':
                return preg_match((string) $arg, (string) $v) ? null : "«{$label}» بصيغة غير صحيحة";
            case 'date':
                $d = \DateTime::createFromFormat('Y-m-d', (string) $v);
                return ($d && $d->format('Y-m-d') === $v) ? null : "«{$label}» يجب أن يكون تاريخاً بصيغة YYYY-MM-DD";
            case 'year':
                return (filter_var($v, FILTER_VALIDATE_INT) !== false && $v >= 1970 && $v <= 2100) ? null : "«{$label}» سنة غير صحيحة";
            case 'hex':
                return preg_match('/^#[0-9a-fA-F]{6}$/', (string) $v) ? null : "«{$label}» يجب أن يكون لوناً مثل #8b5cf6";
            case 'slug':
                return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $v) ? null : "«{$label}» يقبل أحرفاً إنجليزية صغيرة وأرقاماً وشرطات فقط";
            case 'array':
                return is_array($v) ? null : "«{$label}» يجب أن يكون قائمة";
            case 'confirmed':
                return (($data[$field . '_confirmation'] ?? null) === $v) ? null : 'تأكيد كلمة المرور غير مطابق';
            case 'unique':
                $p = explode(',', (string) $arg);
                $sql = "SELECT 1 FROM `{$p[0]}` WHERE `{$p[1]}` = ?" . (isset($p[2]) && $p[2] !== '' ? ' AND id <> ?' : '') . ' LIMIT 1';
                $params = isset($p[2]) && $p[2] !== '' ? [$v, (int) $p[2]] : [$v];
                return DB::value($sql, $params) ? "«{$label}» مستخدم مسبقاً" : null;
            case 'exists':
                $p = explode(',', (string) $arg);
                return DB::value("SELECT 1 FROM `{$p[0]}` WHERE `{$p[1]}` = ? LIMIT 1", [$v]) ? null : "«{$label}» غير موجود";
        }
        return null;
    }
}
