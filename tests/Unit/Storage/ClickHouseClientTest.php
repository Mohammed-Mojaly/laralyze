<?php

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use MohammedMojaly\Laralyze\Storage\ClickHouse\ClickHouseException;
use MohammedMojaly\Laralyze\Storage\ClickHouse\Client;

/**
 * @param  list<Response|Throwable>  $responses
 * @param  array<int, array{request: Request}>  $history
 */
function clickhouse(array $responses, array &$history = [], bool $wait = true): Client
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return new Client(new Guzzle(['handler' => $stack, 'headers' => ['X-ClickHouse-User' => 'lz', 'X-ClickHouse-Key' => 'pw']]), 'laralyze', $wait, 3.0, 'http://ch.test:8123');
}

it('formats parameters as ClickHouse text', function () {
    expect(Client::param('a\'b\\c'))->toBe('a\'b\\c')
        ->and(Client::param(['x', "it's", 'back\\slash']))->toBe("['x','it\\'s','back\\\\slash']")
        ->and(Client::param(true))->toBe('1')
        ->and(Client::param(null))->toBe('\N')
        ->and(Client::param(42))->toBe('42');
});

it('selects with typed parameters and never puts credentials in the URL', function () {
    $history = [];
    $client = clickhouse([new Response(200, [], (string) json_encode(['data' => [['n' => 1]]]))], $history);

    expect($client->select('SELECT {n:UInt8} AS n', ['n' => 1]))->toBe([['n' => 1]]);

    $request = $history[0]['request'];
    parse_str($request->getUri()->getQuery(), $query);

    expect((string) $request->getBody())->toBe('SELECT {n:UInt8} AS n FORMAT JSON')
        ->and($query)->toMatchArray(['database' => 'laralyze', 'param_n' => '1'])
        ->and((string) $request->getUri())->not->toContain('pw')
        ->and($request->getHeaderLine('X-ClickHouse-Key'))->toBe('pw');
});

it('inserts gzipped JSON rows asynchronously, waiting by default', function () {
    $history = [];
    clickhouse([new Response(200)], $history)->insert('laralyze_values', [['key' => 'مرحبا'], ['key' => "a\nb"]]);

    $request = $history[0]['request'];
    parse_str($request->getUri()->getQuery(), $query);

    expect($query)->toMatchArray(['query' => 'INSERT INTO laralyze_values FORMAT JSONEachRow', 'async_insert' => '1', 'wait_for_async_insert' => '1'])
        ->and($request->getHeaderLine('Content-Encoding'))->toBe('gzip')
        ->and(gzdecode((string) $request->getBody()))->toBe('{"key":"مرحبا"}'."\n".'{"key":"a\nb"}'."\n");
});

it('can skip waiting and can write synchronously', function () {
    $history = [];
    clickhouse([new Response(200)], $history, wait: false)->insert('t', [['a' => 1]]);
    clickhouse([new Response(200)], $history)->insert('t', [['a' => 1]], async: false);

    parse_str($history[0]['request']->getUri()->getQuery(), $first);
    parse_str($history[1]['request']->getUri()->getQuery(), $second);

    expect($first['wait_for_async_insert'])->toBe('0')
        ->and($second['async_insert'])->toBe('0');
});

it('sends one insert per table at the same time and skips empty tables', function () {
    $history = [];
    clickhouse([new Response(200), new Response(200)], $history)->insertMany(['a' => [['x' => 1]], 'b' => [], 'c' => [['x' => 2]]]);

    expect($history)->toHaveCount(2);
});

it('throws ClickHouse errors with their message', function () {
    $client = clickhouse([new Response(500, ['X-ClickHouse-Exception-Code' => '60'], 'Code: 60. DB::Exception: Table laralyze.nope does not exist. (UNKNOWN_TABLE) (version 26.9.10.4 (official build))')]);

    expect(fn () => $client->select('SELECT * FROM nope'))->toThrow(ClickHouseException::class, 'Table laralyze.nope does not exist. (UNKNOWN_TABLE)');
});

it('reports the first failed insert after all of them finish', function () {
    $history = [];
    $client = clickhouse([new Response(200), new ConnectException('Connection refused', new Request('POST', 'http://ch.test'))], $history);

    expect(fn () => $client->insertMany(['a' => [['x' => 1]], 'b' => [['x' => 2]]]))->toThrow(ConnectException::class)
        ->and($history)->toHaveCount(2);
});

it('is not affected by Http::fake in the app', function () {
    Http::fake();
    $history = [];

    clickhouse([new Response(200)], $history)->insert('t', [['a' => 1]]);

    expect($history)->toHaveCount(1);
    Http::assertNothingSent();
});

it('reads its settings from config with defaults for missing keys', function () {
    $client = Client::fromConfig(['url' => 'https://ch.example.com:8443/', 'database' => 'monitoring']);

    expect($client->url())->toBe('https://ch.example.com:8443')
        ->and($client->database())->toBe('monitoring')
        ->and(Client::fromConfig([])->url())->toBe('http://127.0.0.1:8123');
});
