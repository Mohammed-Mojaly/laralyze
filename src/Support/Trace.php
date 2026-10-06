<?php

namespace MohammedMojaly\Laralyze\Support;

use Illuminate\Support\Str;
use SplFileObject;
use Throwable;

/**
 * An exception's stack trace as the dashboard shows it: one frame per
 * line, the function it ran in, and the code around lines in your app.
 */
final class Trace
{
    public const FRAMES = 100;

    /**
     * App frames that get the code around their line.
     */
    public const SNIPPETS = 20;

    /**
     * Lines of code shown above and below.
     */
    public const AROUND = 5;

    /**
     * @return list<array{call: string, file: string, line: int, app: bool, code?: array{start: int, lines: list<string>}}>
     */
    public static function frames(Throwable $e): array
    {
        $trace = $e->getTrace();
        $frames = [];
        $snippets = 0;

        // PHP lists where each function was called from; the line that ran
        // inside a function is one entry earlier, or the exception's own.
        $file = $e->getFile();
        $line = $e->getLine();

        for ($i = 0; $i <= count($trace) && count($frames) < self::FRAMES; $i++) {
            if ($file !== '') {
                $app = Location::isApp($file);
                $frame = ['call' => isset($trace[$i]) ? self::call($trace[$i]) : '{main}', 'file' => Location::relative($file), 'line' => $line, 'app' => $app];

                if ($app && $snippets < self::SNIPPETS && ($code = self::snippet($file, $line)) !== null) {
                    $frame['code'] = $code;
                    $snippets++;
                }

                $frames[] = $frame;
            }

            $file = (string) ($trace[$i]['file'] ?? '');
            $line = (int) ($trace[$i]['line'] ?? 0);
        }

        return $frames;
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private static function call(array $frame): string
    {
        $function = (string) ($frame['function'] ?? '');

        // PHP 8.4 names closures "{closure:file:line}"; the frame shows the file already.
        if (str_starts_with($function, '{closure')) {
            $function = '{closure}';
        }

        return isset($frame['class']) ? $frame['class'].($frame['type'] ?? '::').$function.'()' : $function.'()';
    }

    /**
     * @return array{start: int, lines: list<string>}|null
     */
    private static function snippet(string $file, int $line): ?array
    {
        if ($line < 1 || ! is_file($file) || ! is_readable($file)) {
            return null;
        }

        try {
            $start = max(1, $line - self::AROUND);
            $source = new SplFileObject($file);
            $source->seek($start - 1);

            $lines = [];

            while (! $source->eof() && count($lines) < 2 * self::AROUND + 1) {
                $current = $source->current();
                $lines[] = Str::limit(Secrets::mask(rtrim(is_string($current) ? $current : '', "\r\n")), 300);
                $source->next();
            }

            return $lines === [] ? null : ['start' => $start, 'lines' => $lines];
        } catch (Throwable) {
            return null;
        }
    }
}
