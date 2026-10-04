<?php

namespace MohammedMojaly\Laralyze\Dashboard;

use MohammedMojaly\Laralyze\Contracts\Storage;

/**
 * Exceptions as issues: open until someone resolves or ignores them. A
 * resolved one that happens again is reopened.
 */
final class Issues
{
    public const OPEN = 'open';

    public const REOPENED = 'reopened';

    public const RESOLVED = 'resolved';

    public const IGNORED = 'ignored';

    public function __construct(private Storage $storage) {}

    /**
     * The status of each exception, given when each was last seen.
     *
     * @param  array<string, float|int|null>  $lastSeen  exception key => timestamp
     * @return array<string, string>
     */
    public function statuses(array $lastSeen): array
    {
        $marked = $lastSeen === [] ? collect() : $this->storage->values('issue', array_map('strval', array_keys($lastSeen)))->keyBy('key');
        $statuses = [];

        foreach ($lastSeen as $key => $seen) {
            $mark = $marked[(string) $key] ?? null;

            $statuses[(string) $key] = match (true) {
                $mark === null => self::OPEN,
                $mark->value === self::IGNORED => self::IGNORED,
                (float) $seen > $mark->timestamp => self::REOPENED,
                default => self::RESOLVED,
            };
        }

        return $statuses;
    }

    /**
     * When it was resolved, if it is.
     */
    public function resolvedAt(string $key): ?int
    {
        $mark = $this->storage->values('issue', [$key])->first();

        return $mark !== null && $mark->value === self::RESOLVED ? $mark->timestamp : null;
    }

    public function resolve(string $key): void
    {
        $this->storage->put('issue', $key, self::RESOLVED);
    }

    public function ignore(string $key): void
    {
        $this->storage->put('issue', $key, self::IGNORED);
    }

    public function reopen(string $key): void
    {
        $this->storage->forget('issue', $key);
    }
}
