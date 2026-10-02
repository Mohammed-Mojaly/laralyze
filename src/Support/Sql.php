<?php

namespace MohammedMojaly\Laralyze\Support;

use Illuminate\Support\HtmlString;

/**
 * Makes stored SQL easier to read: keywords, strings and numbers get
 * their own colour, and format() puts each clause on its own line.
 */
final class Sql
{
    protected const KEYWORDS = [
        'add', 'all', 'alter', 'and', 'as', 'asc', 'begin', 'between', 'by', 'case', 'commit', 'create', 'cross',
        'delete', 'desc', 'describe', 'distinct', 'drop', 'duplicate', 'else', 'end', 'exists', 'explain', 'false',
        'fetch', 'first', 'for', 'from', 'full', 'group', 'having', 'if', 'ilike', 'in', 'index', 'inner', 'insert',
        'interval', 'into', 'is', 'join', 'key', 'left', 'like', 'limit', 'lock', 'next', 'not', 'null', 'offset',
        'on', 'only', 'or', 'order', 'outer', 'pragma', 'primary', 'release', 'returning', 'right', 'rollback',
        'rows', 'savepoint', 'select', 'session', 'set', 'share', 'show', 'table', 'then', 'top', 'true', 'truncate',
        'union', 'update', 'using', 'values', 'when', 'where', 'with',
    ];

    /**
     * Words that start a new line when they aren't inside parentheses.
     */
    protected const CLAUSES = [
        'select', 'from', 'where', 'having', 'limit', 'offset', 'union', 'values', 'set', 'returning',
        'group', 'order', 'join', 'inner', 'left', 'right', 'cross', 'full', 'and', 'or', 'on',
    ];

    protected const JOIN_PREFIXES = ['inner', 'left', 'right', 'cross', 'full', 'outer'];

    protected const TOKENS = '/
        (?<string>\'(?:[^\'\\\\]|\\\\.|\'\')*\'?)
        | (?<identifier>`[^`]*`?|"[^"]*"?|\[[^\]]*\]?)
        | (?<number>\b\d+(?:\.\d+)?\b)
        | (?<placeholder>\?|:\w+|\$\d+)
        | (?<word>[A-Za-z_][A-Za-z0-9_]*)
        | (?<space>\s+)
        | (?<other>.)
    /xsu';

    public static function highlight(string $sql): HtmlString
    {
        $html = '';

        foreach (self::tokenize($sql) as [$type, $text]) {
            $escaped = e($text);

            $class = match ($type) {
                'word' => in_array(strtolower($text), self::KEYWORDS, true) ? 'kw' : null,
                'string' => 'str',
                'number' => 'num',
                'placeholder' => 'ph',
                'identifier' => 'id',
                default => null,
            };

            $html .= $class === null ? $escaped : "<span class=\"lz-sql-{$class}\">{$escaped}</span>";
        }

        return new HtmlString($html);
    }

    /**
     * One clause per line, conditions indented under it.
     */
    public static function format(string $sql): string
    {
        $tokens = self::tokenize((string) preg_replace('/\s+/', ' ', trim($sql)));
        $out = '';
        $depth = 0;
        $previous = null;
        $lastWord = null;

        foreach ($tokens as $i => [$type, $text]) {
            if ($type === 'other') {
                $depth += match ($text) {
                    '(' => 1,
                    ')' => $depth > 0 ? -1 : 0,
                    default => 0,
                };
            }

            if ($type === 'word' && $depth === 0 && $out !== '' && self::startsClause($tokens, $i, $previous, $lastWord)) {
                $word = strtolower($text);
                $out = rtrim($out)."\n".(in_array($word, ['and', 'or'], true) ? '  ' : '');
            }

            $out .= $text;

            if ($type !== 'space') {
                $previous = [$type, $text];
            }

            if ($type === 'word') {
                $lastWord = strtolower($text);
            }
        }

        return $out;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $tokens
     * @param  array{0: string, 1: string}|null  $previous
     */
    protected static function startsClause(array $tokens, int $i, ?array $previous, ?string $lastWord): bool
    {
        $word = strtolower($tokens[$i][1]);

        if (! in_array($word, self::CLAUSES, true)) {
            return false;
        }

        // left(name, 3) and values(column) are functions, not clauses.
        if (($tokens[$i + 1][1] ?? null) === '(') {
            return false;
        }

        $next = self::nextWord($tokens, $i);

        return match ($word) {
            'join' => ! in_array($lastWord, self::JOIN_PREFIXES, true),
            'inner', 'left', 'right', 'cross', 'full' => in_array($next, ['join', 'outer'], true),
            'group', 'order' => $next === 'by',
            'on' => $next === 'duplicate',
            'and' => $lastWord !== 'between',
            'values', 'set' => ! in_array($previous[1] ?? null, ['=', ','], true),
            default => true,
        };
    }

    /**
     * @param  list<array{0: string, 1: string}>  $tokens
     */
    protected static function nextWord(array $tokens, int $i): ?string
    {
        for ($j = $i + 1; $j < count($tokens); $j++) {
            if ($tokens[$j][0] === 'word') {
                return strtolower($tokens[$j][1]);
            }

            if ($tokens[$j][0] !== 'space') {
                return null;
            }
        }

        return null;
    }

    /**
     * @return list<array{0: string, 1: string}> [type, text]
     */
    protected static function tokenize(string $sql): array
    {
        preg_match_all(self::TOKENS, $sql, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        $tokens = [];

        foreach ($matches as $match) {
            foreach (['string', 'identifier', 'number', 'placeholder', 'word', 'space', 'other'] as $type) {
                if ($match[$type] !== null) {
                    $tokens[] = [$type, $match[$type]];
                    break;
                }
            }
        }

        return $tokens;
    }
}
