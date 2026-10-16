<?php

namespace MohammedMojaly\Laralyze\Alerts;

use MohammedMojaly\Laralyze\Contracts\Storage;

/**
 * What was sent: one entry per message, with each channel's delivery.
 * Entries are kept like other data (30 days by default). Keys start with
 * the time, so the newest read first.
 */
final class History
{
    public const TYPE = 'alert_event';

    /**
     * When each viewer last opened the bell.
     */
    public const SEEN = 'alert_seen';

    public const PAGE = 500;

    public const BELL = 20;

    /**
     * Items listed in one message; the rest are "and N more".
     */
    public const LISTED = 5;

    public const FIRED = 'fired';

    public const RECOVERED = 'recovered';

    public const REMINDER = 'reminder';

    public const TEST = 'test';

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public function __construct(private Storage $storage) {}

    /**
     * Keep an entry, with a pending delivery for each channel.
     *
     * @param  array{rule: string, rule_name: string, metric: string, event: string, title: string, items: list<array{item: string, value: string}>, more: int, text: string, note: string, url: string}  $entry
     * @param  list<string>  $channels
     * @return array<string, mixed>
     */
    public function add(array $entry, array $channels, int $now): array
    {
        $key = sprintf('%010d:%s:%s:%s', $now, $entry['rule'], substr(hash('xxh128', $entry['items'][0]['item'] ?? ''), 0, 12), $entry['event']);
        $entry = [
            ...$entry,
            'key' => $key,
            'at' => $now,
            'deliveries' => array_fill_keys($channels, ['status' => self::PENDING, 'attempts' => 0, 'error' => null, 'at' => null]),
        ];

        $this->save($entry);

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public function save(array $entry): void
    {
        $this->storage->put(self::TYPE, (string) $entry['key'], (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * @return list<array<string, mixed>> Newest first.
     */
    public function latest(int $limit = self::PAGE): array
    {
        $entries = [];

        foreach ($this->storage->values(self::TYPE, limit: $limit) as $row) {
            $entry = json_decode((string) $row->value, true);

            if (is_array($entry) && isset($entry['key'], $entry['at'])) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        $entry = json_decode((string) ($this->storage->values(self::TYPE, [$key])->first()->value ?? ''), true);

        return is_array($entry) ? $entry : null;
    }

    /**
     * Entries with a delivery still to make, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        return array_reverse(array_values(array_filter(
            $this->latest(),
            fn (array $entry) => in_array(self::PENDING, array_column((array) ($entry['deliveries'] ?? []), 'status'), true),
        )));
    }

    /**
     * The bell: the latest entries, failed deliveries first.
     *
     * @return list<array<string, mixed>>
     */
    public function bell(): array
    {
        $entries = $this->latest(self::BELL);

        usort($entries, fn (array $a, array $b) => [(int) self::failed($b), (string) $b['key']] <=> [(int) self::failed($a), (string) $a['key']]);

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public static function failed(array $entry): bool
    {
        return in_array(self::FAILED, array_column((array) ($entry['deliveries'] ?? []), 'status'), true);
    }

    /**
     * When a viewer last opened the bell: by user id, or one marker shared
     * by everyone when there are no users.
     */
    public function seenAt(?string $viewer): int
    {
        return (int) ($this->storage->values(self::SEEN, [$viewer ?? 'shared'])->first()->value ?? 0);
    }

    public function markSeen(?string $viewer, int $at): void
    {
        $this->storage->put(self::SEEN, $viewer ?? 'shared', (string) $at);
    }
}
