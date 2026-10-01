<?php

namespace MohammedMojaly\Laralyze\Recorders;

use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;

/**
 * Counts hits, misses, writes, deletes and failures per key group. Keys
 * are grouped (user:42 → user:*) after the response is sent.
 */
class Cache extends Recorder
{
    protected array $listen = [
        CacheHit::class,
        CacheMissed::class,
        KeyWritten::class,
        KeyForgotten::class,
        KeyWriteFailed::class,
        KeyForgetFailed::class,
    ];

    protected const MAX_KEYS = 2_000;

    protected const TYPES = [
        CacheHit::class => 'cache_hit',
        CacheMissed::class => 'cache_miss',
        KeyWritten::class => 'cache_write',
        KeyForgotten::class => 'cache_delete',
    ];

    /**
     * [type][key] => count
     *
     * @var array<string, array<string, int>>
     */
    protected array $pending = [];

    protected int $keys = 0;

    public function record(CacheEvent $event): void
    {
        $type = self::TYPES[$event::class] ?? 'cache_failure';

        if (! isset($this->pending[$type][$event->key]) && ++$this->keys > self::MAX_KEYS) {
            $this->digest();
        }

        $this->pending[$type][$event->key] = ($this->pending[$type][$event->key] ?? 0) + 1;
    }

    public function digest(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        $this->keys = 0;

        foreach ($pending as $type => $keys) {
            foreach ($keys as $key => $count) {
                $key = (string) $key;

                if ($this->ignoresKey($key)) {
                    continue;
                }

                $this->laralyze->merge($type, $this->group($key), ['count' => $count]);
            }
        }
    }

    /**
     * Framework internals and other tools' keys are noise here.
     */
    protected function ignoresKey(string $key): bool
    {
        foreach (['illuminate:', 'laravel:', 'laralyze:', 'framework/schedule', 'telescope:', 'horizon:'] as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        // Session ids.
        if (preg_match('/^[a-zA-Z0-9]{40}$/', $key)) {
            return true;
        }

        return $this->shouldIgnore($key);
    }
}
