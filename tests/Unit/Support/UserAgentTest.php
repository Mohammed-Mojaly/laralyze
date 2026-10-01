<?php

use MohammedMojaly\Laralyze\Support\UserAgent;

it('recognises real browsers and devices', function (string $agent, string $device, string $os, string $browser) {
    expect(UserAgent::parse($agent))->toBe(['device' => $device, 'os' => $os, 'browser' => $browser, 'bot' => null]);
})->with([
    'Chrome on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', 'Desktop', 'Windows', 'Chrome'],
    'Edge on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.2792.52', 'Desktop', 'Windows', 'Edge'],
    'Safari on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15', 'Desktop', 'macOS', 'Safari'],
    'Firefox on Linux' => ['Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0', 'Desktop', 'Linux', 'Firefox'],
    'Safari on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1', 'Mobile', 'iOS', 'Safari'],
    'Chrome on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/129.0.6668.46 Mobile/15E148 Safari/604.1', 'Mobile', 'iOS', 'Chrome'],
    'Safari on iPad' => ['Mozilla/5.0 (iPad; CPU OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1', 'Tablet', 'iOS', 'Safari'],
    'Chrome on Android phone' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.6668.81 Mobile Safari/537.36', 'Mobile', 'Android', 'Chrome'],
    'Samsung on Android tablet' => ['Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Safari/537.36', 'Tablet', 'Android', 'Samsung Internet'],
    'Opera on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 OPR/114.0.0.0', 'Desktop', 'Windows', 'Opera'],
    'Chrome on ChromeOS' => ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', 'Desktop', 'ChromeOS', 'Chrome'],
]);

it('tells bots apart from visitors', function (string $agent, string $bot) {
    expect(UserAgent::parse($agent)['bot'])->toBe($bot);
})->with([
    ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'Googlebot'],
    ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', 'Bingbot'],
    ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)', 'GPTBot'],
    ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', 'ClaudeBot'],
    ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', 'Facebook'],
    ['Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)', 'Slack'],
    ['WhatsApp/2.23.20.0', 'WhatsApp'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/129.0.0.0 Safari/537.36', 'Headless browser'],
    ['curl/8.7.1', 'Script'],
    ['python-requests/2.32.3', 'Script'],
    ['Mozilla/5.0 (compatible; SomeNewCrawler/1.0)', 'Other bot'],
    ['', 'Unknown'],
]);
