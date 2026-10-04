<?php

namespace MohammedMojaly\Laralyze\Storage\ClickHouse;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * ClickHouse over its HTTP interface. Guzzle directly, not Laravel's Http
 * client, so Laralyze never records its own writes and an app's
 * Http::fake() can't swallow them.
 */
class Client
{
    /**
     * Seconds a dashboard read may take; writes use the configured timeout.
     */
    protected const READ_TIMEOUT = 30.0;

    public function __construct(
        protected ClientInterface $http,
        protected string $database,
        protected bool $wait,
        protected float $timeout,
        protected string $url,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $url = rtrim((string) ($config['url'] ?? 'http://127.0.0.1:8123'), '/');

        return new self(
            new Guzzle([
                'base_uri' => $url.'/',
                'connect_timeout' => 1.0,
                'http_errors' => false,
                // Headers, not the URL, so credentials never reach access logs.
                'headers' => [
                    'X-ClickHouse-User' => (string) ($config['username'] ?? 'default'),
                    'X-ClickHouse-Key' => (string) ($config['password'] ?? ''),
                ],
            ]),
            (string) ($config['database'] ?? 'default'),
            (bool) ($config['wait'] ?? true),
            (float) ($config['timeout'] ?? 3),
            $url,
        );
    }

    /**
     * Where ClickHouse runs, without credentials.
     */
    public function url(): string
    {
        return $this->url;
    }

    public function database(): string
    {
        return $this->database;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $response = $this->send($sql.' FORMAT JSON', $params);

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['data'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function statement(string $sql, array $params = []): void
    {
        $this->send($sql, $params);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function insert(string $table, array $rows, bool $async = true): void
    {
        if ($rows !== []) {
            $this->settle($this->insertAsync($table, $rows, $async)->wait());
        }
    }

    /**
     * One insert per table, all at once, so a flush waits for the slowest
     * rather than the sum.
     *
     * @param  array<string, list<array<string, mixed>>>  $tables
     */
    public function insertMany(array $tables): void
    {
        $promises = [];

        foreach ($tables as $table => $rows) {
            if ($rows !== []) {
                $promises[$table] = $this->insertAsync($table, $rows, true);
            }
        }

        $failure = null;

        // All requests are already in flight; waiting on one drives them all.
        foreach ($promises as $promise) {
            try {
                $this->settle($promise->wait());
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * A value as ClickHouse reads a query parameter.
     */
    public static function param(mixed $value): string
    {
        return match (true) {
            $value === null => '\N',
            is_bool($value) => $value ? '1' : '0',
            is_array($value) => '['.implode(',', array_map(fn ($item) => "'".addcslashes((string) $item, "\\'")."'", $value)).']',
            default => (string) $value,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function insertAsync(string $table, array $rows, bool $async): PromiseInterface
    {
        $body = '';

        foreach ($rows as $row) {
            $body .= json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n";
        }

        return $this->http->requestAsync('POST', '', [
            'query' => [
                'database' => $this->database,
                'query' => "INSERT INTO {$table} FORMAT JSONEachRow",
                // The server batches small inserts from many processes into one write.
                'async_insert' => $async ? '1' : '0',
                'wait_for_async_insert' => $this->wait ? '1' : '0',
            ],
            'headers' => ['Content-Encoding' => 'gzip'],
            'body' => (string) gzencode($body, 1),
            'timeout' => $this->timeout,
            'http_errors' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function send(string $sql, array $params): ResponseInterface
    {
        $query = ['database' => $this->database];

        foreach ($params as $name => $value) {
            $query['param_'.$name] = self::param($value);
        }

        return $this->settle($this->http->request('POST', '', ['query' => $query, 'body' => $sql, 'timeout' => self::READ_TIMEOUT, 'http_errors' => false]));
    }

    protected function settle(mixed $response): ResponseInterface
    {
        if (! $response instanceof ResponseInterface) {
            throw new ClickHouseException('ClickHouse sent no response.');
        }

        if ($response->getStatusCode() !== 200) {
            throw ClickHouseException::fromResponse($response->getStatusCode(), (string) $response->getBody());
        }

        return $response;
    }
}
