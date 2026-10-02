<?php

namespace MohammedMojaly\Laralyze\Support;

/**
 * Small icons for systems, browsers, devices and bots on the Visits page.
 * Brand marks come from Simple Icons; the rest are drawn to match the
 * dashboard's own icons.
 */
final class Brands
{
    /**
     * Line icons, 24×24, stroked.
     */
    private const GENERIC = [
        'Desktop' => '<rect x="3" y="4" width="18" height="12.5" rx="2"/><path d="M8.5 20.5h7"/><path d="M12 16.5v4"/>',
        'Mobile' => '<rect x="6.5" y="2.5" width="11" height="19" rx="2.5"/><path d="M10.5 18.5h3"/>',
        'Tablet' => '<rect x="4" y="2.5" width="16" height="19" rx="2.5"/><path d="M10.5 18.5h3"/>',
        'globe' => '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17"/><path d="M12 3.5c2.3 2.4 3.4 5.2 3.4 8.5s-1.1 6.1-3.4 8.5c-2.3-2.4-3.4-5.2-3.4-8.5S9.7 5.9 12 3.5Z"/>',
        'bot' => '<rect x="4.5" y="8" width="15" height="11" rx="3"/><path d="M12 8V4.5"/><circle cx="12" cy="3.8" r=".8"/><circle cx="9.3" cy="13" r="1.1"/><circle cx="14.7" cy="13" r="1.1"/><path d="M2.5 12.5v2.5M21.5 12.5v2.5"/>',
        'Script' => '<rect x="3" y="4.5" width="18" height="15" rx="2"/><path d="m7 10 2.5 2L7 14"/><path d="M12 14.5h4.5"/>',
        'Headless browser' => '<rect x="3" y="4.5" width="18" height="15" rx="2"/><path d="M3 8.5h18"/><path d="M9 14h6" stroke-dasharray="1.5 2"/>',
        'Uptime monitor' => '<path d="M3 12h4l2-5 4 10 2-5h6"/>',
    ];

    /**
     * @var array<string, array{0: string, 1: string|null}>|null
     */
    private static ?array $brands = null;

    /**
     * The SVG for a name, or a fallback for its group.
     */
    public static function svg(string $name, string $fallback = 'globe'): string
    {
        self::$brands ??= require dirname(__DIR__, 2).'/resources/icons/brands.php';

        if (isset(self::$brands[$name])) {
            [$path, $colour] = self::$brands[$name];
            $style = $colour === null ? '' : ' style="color: '.$colour.'"';

            return '<svg class="lz-mark" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"'.$style.'><path d="'.$path.'"/></svg>';
        }

        $lines = self::GENERIC[$name] ?? self::GENERIC[$fallback] ?? self::GENERIC['globe'];

        return '<svg class="lz-mark lz-mark-line" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$lines.'</svg>';
    }
}
