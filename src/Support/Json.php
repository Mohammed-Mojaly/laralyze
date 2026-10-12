<?php

namespace MohammedMojaly\Laralyze\Support;

use Illuminate\Support\HtmlString;

/**
 * Values shown as JSON, indented by two spaces, with keys, strings and
 * other values in their own colors.
 */
final class Json
{
    protected const TOKENS = '/("(?:[^"\\\\]|\\\\.)*")(\s*:)?|\b(?:true|false|null)\b|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/';

    public static function pretty(mixed $value): string
    {
        $json = (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return (string) preg_replace_callback('/^(?: {4})+/m', fn (array $indent) => str_repeat(' ', intdiv(strlen($indent[0]), 2)), $json);
    }

    public static function highlight(mixed $value): HtmlString
    {
        // Everything between the tokens is punctuation and spaces, which need no escaping.
        return new HtmlString((string) preg_replace_callback(self::TOKENS, function (array $match) {
            if (($match[1] ?? '') === '') {
                $class = is_numeric($match[0]) ? 'lz-json-num' : 'lz-json-lit';

                return '<span class="'.$class.'">'.$match[0].'</span>';
            }

            $class = isset($match[2]) ? 'lz-json-key' : 'lz-json-str';

            return '<span class="'.$class.'">'.e($match[1]).'</span>'.($match[2] ?? '');
        }, self::pretty($value)));
    }

    /**
     * One value of a context, as a table cell shows it: text as it is, other values colored.
     */
    public static function cell(mixed $value): HtmlString
    {
        return match (true) {
            is_string($value) => new HtmlString(e($value)),
            is_int($value), is_float($value) => new HtmlString('<span class="lz-json-num">'.e((string) json_encode($value)).'</span>'),
            is_bool($value), $value === null => new HtmlString('<span class="lz-json-lit">'.json_encode($value).'</span>'),
            default => new HtmlString('<pre class="lz-code lz-json">'.self::highlight($value).'</pre>'),
        };
    }
}
