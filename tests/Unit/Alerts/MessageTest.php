<?php

use MohammedMojaly\Laralyze\Alerts\Message;
use MohammedMojaly\Laralyze\Alerts\Metrics\Catalog;
use MohammedMojaly\Laralyze\Alerts\Rule;

function sampleMessage(array $overrides = []): Message
{
    return Message::fromEntry([
        'event' => 'fired',
        'rule' => 'R1',
        'rule_name' => 'API errors',
        'title' => 'API errors: <b>7.5%</b> & @everyone',
        'items' => [['item' => 'GET /a', 'value' => '7.5%'], ['item' => 'GET /*b*', 'value' => '6%']],
        'more' => 3,
        'text' => '',
        'note' => 'Check <!channel> first',
        'url' => 'https://app.test/laralyze/alerts',
        'at' => 1791900000,
        ...$overrides,
    ]);
}

it('escapes for Slack', function () {
    expect(sampleMessage()->slack())
        ->toContain('*API errors: &lt;b&gt;7.5%&lt;/b&gt; &amp; @everyone*')
        ->toContain('Check &lt;!channel&gt; first')
        ->toContain('and 3 more')
        ->toContain('<https://app.test/laralyze/alerts|Open in Laralyze>');
});

it('escapes for Discord', function () {
    expect(sampleMessage()->discord())
        ->toStartWith('**API errors')
        ->toContain('GET /\*b\*');
});

it('escapes for Telegram', function () {
    expect(sampleMessage()->telegram())
        ->toContain('<b>API errors: &lt;b&gt;7.5%&lt;/b&gt; &amp; @everyone</b>')
        ->toContain('<a href="https://app.test/laralyze/alerts">Open in Laralyze</a>');
});

it('stays within Telegram\'s 4,096 characters and keeps the link', function () {
    $telegram = sampleMessage(['text' => str_repeat('<&> ', 2_000)])->telegram();

    expect(mb_strlen(html_entity_decode(strip_tags($telegram))))->toBeLessThanOrEqual(4_096)
        ->and($telegram)->toStartWith('<b>API errors')
        ->toEndWith('<a href="https://app.test/laralyze/alerts">Open in Laralyze</a>')
        ->toContain('&lt;&amp;&gt;');
});

it('says when it is back to normal, a reminder, or a test', function () {
    expect(sampleMessage(['event' => 'recovered'])->heading())->toStartWith('Back to normal: ')
        ->and(sampleMessage(['event' => 'reminder'])->heading())->toStartWith('Still firing: ')
        ->and(sampleMessage(['event' => 'test'])->heading())->toStartWith('Test: ')
        ->and(sampleMessage()->heading())->toStartWith('API errors');
});

it('lists the items only when there are several', function () {
    expect(sampleMessage(['items' => [['item' => 'GET /a', 'value' => '7.5%']], 'more' => 0])->plain())->not->toContain('•')
        ->and(sampleMessage()->plain())->toContain('• GET /a: 7.5%');
});

it('builds a title from the rule', function () {
    $metric = app(Catalog::class)->find('requests.error_rate');
    $rule = Rule::fromArray('r', ['name' => 'API errors', 'metric' => 'requests.error_rate', 'operator' => '>', 'threshold' => 5]);

    expect(Message::title($rule, $metric, 'GET /a', '7.5%'))->toBe('API errors: GET /a 7.5% (> 5%)')
        ->and(Message::title($rule, $metric, '', '7.5%'))->toBe('API errors: 7.5% (> 5%)')
        ->and(Message::title($rule, $metric, '', ''))->toBe('API errors')
        ->and(Message::title($rule->with(['title' => '{rule} hit {value} on {item}, limit {threshold}']), $metric, 'GET /a', '7.5%'))->toBe('API errors hit 7.5% on GET /a, limit 5%')
        ->and(Message::title(Rule::fromArray('r', ['name' => 'Exceptions', 'metric' => 'exceptions.new']), app(Catalog::class)->find('exceptions.new'), 'New exception: RuntimeException', ''))->toBe('Exceptions: New exception: RuntimeException');
});
