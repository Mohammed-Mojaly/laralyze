<?php

namespace Laralyze\Support;

/**
 * A deliberately small user-agent parser: enough to tell device type, OS,
 * browser and well-known bots apart. Results are kept per process.
 */
final class UserAgent
{
    /**
     * Bot names by the pattern that gives them away. Checked in order.
     */
    protected const BOTS = [
        'Googlebot' => '/Googlebot|Google-InspectionTool|AdsBot-Google|Mediapartners-Google/i',
        'Bingbot' => '/bingbot|BingPreview/i',
        'DuckDuckBot' => '/DuckDuckBot/i',
        'Yandex' => '/YandexBot|YandexImages/i',
        'Baidu' => '/Baiduspider/i',
        'Applebot' => '/Applebot/i',
        'GPTBot' => '/GPTBot|ChatGPT-User|OAI-SearchBot/i',
        'ClaudeBot' => '/ClaudeBot|Claude-Web|anthropic-ai/i',
        'PerplexityBot' => '/PerplexityBot/i',
        'Facebook' => '/facebookexternalhit|facebookcatalog|meta-externalagent/i',
        'Twitter' => '/Twitterbot/i',
        'LinkedIn' => '/LinkedInBot/i',
        'Slack' => '/Slackbot|Slack-ImgProxy/i',
        'Discord' => '/Discordbot/i',
        'WhatsApp' => '/WhatsApp/i',
        'Telegram' => '/TelegramBot/i',
        'Ahrefs' => '/AhrefsBot/i',
        'Semrush' => '/SemrushBot/i',
        'Uptime monitor' => '/UptimeRobot|Pingdom|StatusCake|Better Uptime|Site24x7/i',
        'Headless browser' => '/HeadlessChrome|PhantomJS|Puppeteer|Playwright/i',
        'Script' => '/^(curl|Wget|python-requests|python-urllib|Go-http-client|axios|node-fetch|okhttp|Java\/|libwww-perl|GuzzleHttp|Symfony HttpClient)/i',
        'Other bot' => '/bot\b|crawl|spider|slurp|scrape|fetcher|monitor/i',
    ];

    /**
     * @var array<string, array{device: string, os: string, browser: string, bot: ?string}>
     */
    protected static array $cache = [];

    /**
     * @return array{device: string, os: string, browser: string, bot: ?string}
     */
    public static function parse(string $userAgent): array
    {
        if (count(self::$cache) > 1_000) {
            self::$cache = [];
        }

        return self::$cache[$userAgent] ??= [
            'device' => self::device($userAgent),
            'os' => self::os($userAgent),
            'browser' => self::browser($userAgent),
            'bot' => self::bot($userAgent),
        ];
    }

    protected static function bot(string $ua): ?string
    {
        if (trim($ua) === '') {
            return 'Unknown';
        }

        foreach (self::BOTS as $name => $pattern) {
            if (preg_match($pattern, $ua)) {
                return $name;
            }
        }

        return null;
    }

    protected static function device(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/iPad|Tablet|PlayBook|Silk|Kindle|Android(?!.*Mobile)/i', $ua) => 'Tablet',
            (bool) preg_match('/Mobi|iPhone|iPod|Windows Phone|BlackBerry|Opera Mini/i', $ua) => 'Mobile',
            default => 'Desktop',
        };
    }

    protected static function os(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/i', $ua) => 'iOS',
            (bool) preg_match('/Android/i', $ua) => 'Android',
            (bool) preg_match('/Windows/i', $ua) => 'Windows',
            (bool) preg_match('/CrOS/', $ua) => 'ChromeOS',
            (bool) preg_match('/Macintosh|Mac OS X/i', $ua) => 'macOS',
            (bool) preg_match('/Linux|X11|Ubuntu|Fedora/i', $ua) => 'Linux',
            default => 'Other',
        };
    }

    protected static function browser(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/Edg(e|A|iOS)?\//', $ua) => 'Edge',
            (bool) preg_match('/OPR\/|Opera/', $ua) => 'Opera',
            (bool) preg_match('/SamsungBrowser/', $ua) => 'Samsung Internet',
            (bool) preg_match('/YaBrowser/', $ua) => 'Yandex',
            (bool) preg_match('/Firefox|FxiOS/', $ua) => 'Firefox',
            (bool) preg_match('/Chrome|CriOS/', $ua) => 'Chrome',
            (bool) preg_match('/Safari/', $ua) => 'Safari',
            default => 'Other',
        };
    }
}
