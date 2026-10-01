<?php

namespace Laralyze\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool isEnabled()
 * @method static bool isRecording()
 * @method static void stopRecording()
 * @method static void startRecording()
 * @method static mixed ignore(callable $callback)
 * @method static mixed rescue(callable $callback, mixed $default = null)
 * @method static \Laralyze\Laralyze handleExceptionsUsing(?callable $handler)
 * @method static \Laralyze\Metrics\PendingMetric record(string $type, string $key, int|float $value = 1, ?int $timestamp = null)
 * @method static void set(string $type, string $key, string $value, ?int $timestamp = null)
 * @method static \Laralyze\Metrics\Buffer buffer()
 * @method static void flush()
 * @method static void merge(string $type, string $key, array<string, int|float> $aggregates, ?int $timestamp = null)
 * @method static \Laralyze\Laralyze filter(callable $filter)
 * @method static \Laralyze\Laralyze user(callable $resolver)
 * @method static array{name: string, extra: string} describeUser(\Illuminate\Contracts\Auth\Authenticatable $user)
 *
 * @see \Laralyze\Laralyze
 */
class Laralyze extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Laralyze\Laralyze::class;
    }
}
