<?php

namespace MohammedMojaly\Laralyze\Alerts;

use MohammedMojaly\Laralyze\Alerts\Metrics\Catalog;
use MohammedMojaly\Laralyze\Alerts\Metrics\EventMetric;
use MohammedMojaly\Laralyze\Alerts\Metrics\Metric;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Laralyze;
use MohammedMojaly\Laralyze\Support\Format;

/**
 * One look at every rule: compare, move each item's state, and write a
 * history entry for each message to send.
 */
final class Look
{
    public function __construct(
        private Storage $storage,
        private Rules $rules,
        private States $states,
        private History $history,
        private Catalog $catalog,
        private Channels $channels,
        private Laralyze $laralyze,
    ) {}

    public function run(int $now): void
    {
        $rules = $this->rules->all();
        $firing = $this->states->firing();

        // A rule that is gone or off holds nothing firing; a muted one keeps its states.
        foreach (array_keys($firing) as $id) {
            if (! isset($rules[$id]) || ! $rules[$id]->enabled) {
                $this->states->forgetRule((string) $id);
                unset($firing[$id]);
            }
        }

        foreach ($rules as $rule) {
            $metric = $this->catalog->find($rule->metric);

            if ($metric === null || ! $rule->enabled || $rule->muted($now) || ! $rule->dueAt($now)) {
                continue;
            }

            // One rule that fails to read never stops the others.
            $this->laralyze->rescue(fn () => $metric instanceof EventMetric
                ? $this->tellEvents($rule, $metric, $now)
                : $this->check($rule, $metric, $firing[$rule->id] ?? [], $now));
        }
    }

    /**
     * The value a rule sees now, for the form.
     */
    public function preview(Rule $rule, int $now): string
    {
        $metric = $this->catalog->find($rule->metric);

        if ($metric === null) {
            return '';
        }

        $values = array_filter($metric->measure($this->storage, $rule, $now), fn (?float $value) => $value !== null);

        if ($values === []) {
            return $metric->kind() === Metric::EVENT ? 'Nothing in the last hour.' : 'No data in this window yet.';
        }

        if ($metric->kind() === Metric::EVENT) {
            return Format::number(array_sum($values)).' in the last hour';
        }

        arsort($values);
        $item = (string) array_key_first($values);

        return $item === Metric::ALL
            ? 'Now: '.$metric->format($values[$item])
            : 'Highest now: '.$metric->format($values[$item]).' ('.$metric->itemLabel($item).')';
    }

    public static function historyUrl(string $rule): string
    {
        return route('laralyze.page', ['page' => 'alerts', 'tab' => 'history', 'rule' => $rule]);
    }

    /**
     * @param  array<string|int, array{since: int, clean: int, reminded: int, value: float|null}>  $states  This rule's firing items.
     */
    private function check(Rule $rule, Metric $metric, array $states, int $now): void
    {
        if ($rule->threshold === null) {
            return;
        }

        $told = [History::FIRED => [], History::REMINDER => [], History::RECOVERED => []];

        // A firing item with no data now is a clean look: not breaching.
        foreach ($metric->measure($this->storage, $rule, $now) + array_fill_keys(array_keys($states), null) as $item => $value) {
            $item = (string) $item;
            $state = $states[$item] ?? null;
            $breaches = $value !== null && $rule->breaches($value);

            if ($breaches && $state === null) {
                $this->states->put($rule->id, $item, ['since' => $now, 'clean' => 0, 'reminded' => $now, 'value' => $value]);
                $told[History::FIRED][$item] = $value;
            } elseif ($breaches) {
                $remind = $rule->remind > 0 && $now - $state['reminded'] >= $rule->remind;

                if ($remind || $state['clean'] > 0) {
                    $this->states->put($rule->id, $item, ['since' => $state['since'], 'clean' => 0, 'reminded' => $remind ? $now : $state['reminded'], 'value' => $value]);
                }

                if ($remind) {
                    $told[History::REMINDER][$item] = $value;
                }
            } elseif ($state !== null) {
                if ($state['clean'] >= 1) {
                    $this->states->forget($rule->id, $item);
                    $told[History::RECOVERED][$item] = $value;
                } else {
                    $this->states->put($rule->id, $item, [...$state, 'clean' => 1]);
                }
            }
        }

        foreach ($told as $event => $items) {
            if ($items !== []) {
                $this->tell($rule, $metric, $event, array_map(fn (int|string $item, ?float $value) => [
                    'item' => (string) $item === Metric::ALL ? ($rule->filter?->describe() ?? '') : $metric->itemLabel((string) $item),
                    'value' => $metric->format($value),
                ], array_keys($items), $items), $now);
            }
        }
    }

    /**
     * Each new event is told once per rule.
     */
    private function tellEvents(Rule $rule, EventMetric $metric, int $now): void
    {
        $events = $metric->events($this->storage, $rule, $now);

        if ($events === []) {
            return;
        }

        $sent = $this->storage->values(Alerts::SENT, array_map(fn (array $event) => $rule->id.':'.$event['id'], $events))->pluck('key')->all();
        $new = array_values(array_filter($events, fn (array $event) => ! in_array($rule->id.':'.$event['id'], $sent, true)));

        if ($new === []) {
            return;
        }

        $this->tell(
            $rule,
            $metric,
            History::FIRED,
            array_map(fn (array $event) => ['item' => $event['item'], 'value' => ''], $new),
            $now,
            count($new) === 1 ? $new[0]['text'] : '',
            count($new) === 1 ? ($new[0]['url'] ?? null) : null,
        );

        foreach ($new as $event) {
            $this->storage->put(Alerts::SENT, $rule->id.':'.$event['id'], '1');
        }
    }

    /**
     * @param  list<array{item: string, value: string}>  $items
     */
    private function tell(Rule $rule, Metric $metric, string $event, array $items, int $now, string $text = '', ?string $url = null): void
    {
        $this->history->add([
            'rule' => $rule->id,
            'rule_name' => $rule->name,
            'metric' => $rule->metric,
            'event' => $event,
            'title' => Message::title($rule, $metric, $items[0]['item'], $event === History::RECOVERED && $items[0]['value'] === '—' ? '' : $items[0]['value']),
            'items' => array_slice($items, 0, History::LISTED),
            'more' => max(0, count($items) - History::LISTED),
            'text' => $text,
            'note' => $rule->note,
            'url' => $url ?? self::historyUrl($rule->id),
        ], $this->channels->resolve($rule->channels), $now);
    }
}
