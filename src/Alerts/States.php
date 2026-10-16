<?php

namespace MohammedMojaly\Laralyze\Alerts;

use MohammedMojaly\Laralyze\Contracts\Storage;

/**
 * What's firing now, per rule and item. A state exists only while its
 * item isn't ok, so this stays as small as what's firing.
 */
final class States
{
    public const TYPE = 'alert_state';

    public function __construct(private Storage $storage) {}

    /**
     * @return array<string, array<string|int, array{rule: string, item: string, since: int, clean: int, reminded: int, value: float|null}>>
     */
    public function firing(): array
    {
        $states = [];

        foreach ($this->storage->values(self::TYPE) as $row) {
            $state = json_decode((string) $row->value, true);

            if (is_array($state) && is_string($state['rule'] ?? null) && isset($state['item'])) {
                $states[$state['rule']][(string) $state['item']] = [
                    'rule' => $state['rule'],
                    'item' => (string) $state['item'],
                    'since' => (int) ($state['since'] ?? 0),
                    'clean' => (int) ($state['clean'] ?? 0),
                    'reminded' => (int) ($state['reminded'] ?? 0),
                    'value' => isset($state['value']) ? (float) $state['value'] : null,
                ];
            }
        }

        return $states;
    }

    /**
     * @param  array{since: int, clean: int, reminded: int, value: float|null}  $state
     */
    public function put(string $rule, string $item, array $state): void
    {
        $this->storage->put(self::TYPE, self::key($rule, $item), (string) json_encode([
            'rule' => $rule,
            'item' => $item,
            'since' => $state['since'],
            'clean' => $state['clean'],
            'reminded' => $state['reminded'],
            'value' => $state['value'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function forget(string $rule, string $item): void
    {
        $this->storage->forget(self::TYPE, self::key($rule, $item));
    }

    public function forgetRule(string $rule): void
    {
        foreach ($this->storage->values(self::TYPE, keyPrefix: $rule.':') as $row) {
            $this->storage->forget(self::TYPE, (string) $row->key);
        }
    }

    public static function key(string $rule, string $item): string
    {
        return $rule.':'.hash('xxh128', $item);
    }
}
