<?php

namespace MohammedMojaly\Laralyze\Alerts;

/**
 * One alert rule, made on the dashboard: a metric, a condition, and where to tell.
 */
final class Rule
{
    public const WINDOWS = [300 => '5 minutes', 900 => '15 minutes', 3_600 => '1 hour', 86_400 => '24 hours'];

    public const REMINDERS = [0 => 'Never', 3_600 => 'Every hour', 21_600 => 'Every 6 hours', 86_400 => 'Every day'];

    public const OPERATORS = ['>', '>=', '<', '<=', '=', '!='];

    /**
     * How the dashboard says each operator.
     */
    public const OPERATOR_WORDS = ['>' => 'above', '>=' => 'at least', '<' => 'below', '<=' => 'at most', '=' => 'exactly', '!=' => 'not'];

    public const TOGETHER = 'together';

    public const EACH = 'each';

    public const MUTED_FOREVER = -1;

    /**
     * Every channel set in .env when the message goes out.
     */
    public const ALL_CHANNELS = 'all';

    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $metric,
        public readonly string $operator = '>',
        public readonly ?float $threshold = null,
        public readonly int $window = 300,
        public readonly ?Filter $filter = null,
        public readonly string $mode = self::TOGETHER,
        public readonly array $channels = [],
        public readonly int $remind = 0,
        public readonly string $title = '',
        public readonly string $note = '',
        public readonly bool $enabled = true,
        public readonly ?int $mutedUntil = null,
        public readonly int $createdAt = 0,
        public readonly int $updatedAt = 0,
    ) {}

    public static function fromJson(string $id, string $json): ?self
    {
        $data = json_decode($json, true);

        return is_array($data) && is_string($data['metric'] ?? null) ? self::fromArray($id, $data) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $id, array $data): self
    {
        $window = (int) ($data['window'] ?? 300);
        $remind = (int) ($data['remind'] ?? 0);
        $channels = array_filter(array_map(fn (mixed $channel) => (string) $channel, (array) ($data['channels'] ?? [])), fn (string $channel) => $channel !== '');

        return new self(
            id: $id,
            name: mb_substr(trim((string) ($data['name'] ?? '')), 0, 100),
            metric: (string) ($data['metric'] ?? ''),
            operator: in_array($data['operator'] ?? null, self::OPERATORS, true) ? (string) $data['operator'] : '>',
            threshold: is_numeric($data['threshold'] ?? null) ? (float) $data['threshold'] : null,
            window: isset(self::WINDOWS[$window]) ? $window : 300,
            filter: Filter::fromArray($data['filter'] ?? null),
            mode: ($data['mode'] ?? null) === self::EACH ? self::EACH : self::TOGETHER,
            channels: array_values(array_unique($channels)),
            remind: isset(self::REMINDERS[$remind]) ? $remind : 0,
            title: mb_substr(trim((string) ($data['title'] ?? '')), 0, 150),
            note: mb_substr(trim((string) ($data['note'] ?? '')), 0, 1_000),
            enabled: (bool) ($data['enabled'] ?? true),
            mutedUntil: is_numeric($data['muted_until'] ?? null) ? (int) $data['muted_until'] : null,
            createdAt: (int) ($data['created_at'] ?? 0),
            updatedAt: (int) ($data['updated_at'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'metric' => $this->metric,
            'operator' => $this->operator,
            'threshold' => $this->threshold,
            'window' => $this->window,
            'filter' => $this->filter?->toArray(),
            'mode' => $this->mode,
            'channels' => $this->channels,
            'remind' => $this->remind,
            'title' => $this->title,
            'note' => $this->note,
            'enabled' => $this->enabled,
            'muted_until' => $this->mutedUntil,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $changes  Keys as in toArray().
     */
    public function with(array $changes): self
    {
        return self::fromArray($this->id, [...$this->toArray(), ...$changes]);
    }

    public function muted(int $now): bool
    {
        return $this->mutedUntil === self::MUTED_FOREVER || ($this->mutedUntil !== null && $this->mutedUntil > $now);
    }

    public function breaches(float $value): bool
    {
        if ($this->threshold === null) {
            return false;
        }

        return match ($this->operator) {
            '>' => $value > $this->threshold,
            '>=' => $value >= $this->threshold,
            '<' => $value < $this->threshold,
            '<=' => $value <= $this->threshold,
            '=' => abs($value - $this->threshold) < 1e-9,
            '!=' => abs($value - $this->threshold) >= 1e-9,
            default => false,
        };
    }

    /**
     * A 24-hour window changes slowly: it's checked every five minutes.
     */
    public function dueAt(int $now): bool
    {
        return $this->window < 86_400 || intdiv($now, 60) % 5 === 0;
    }
}
