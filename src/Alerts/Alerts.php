<?php

namespace MohammedMojaly\Laralyze\Alerts;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Http;
use MohammedMojaly\Laralyze\Alerts\Metrics\Catalog;
use MohammedMojaly\Laralyze\Alerts\Metrics\Metric;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Laralyze;
use Throwable;

/**
 * The alerts check, every minute from the scheduler: make the default
 * rules once, look at every rule, send what's pending, then ping the
 * heartbeat. Nothing throws out of it.
 */
class Alerts
{
    /**
     * Events already told, keyed "{rule}:{event id}".
     */
    public const SENT = 'alert_sent';

    public const HEARTBEAT_TIMEOUT = 2;

    public function __construct(
        protected Storage $storage,
        protected Rules $rules,
        protected Look $look,
        protected Deliveries $deliveries,
        protected History $history,
        protected Channels $channels,
        protected Catalog $catalog,
        protected Laralyze $laralyze,
        protected Repository $config,
    ) {}

    public function run(): void
    {
        $this->laralyze->rescue(fn () => $this->laralyze->ignore(function () {
            // Storage out of reach: nothing may fire or recover on data that isn't there.
            if (! $this->reachable()) {
                return;
            }

            $this->rules->createDefaults();
            $this->look->run(now()->getTimestamp());
            $this->deliveries->send();
            $this->heartbeat();
        }));
    }

    /**
     * A sample message for a rule, sent now and kept in history as a test.
     *
     * @return array<string, mixed>
     */
    public function test(Rule $rule): array
    {
        return $this->laralyze->ignore(function () use ($rule) {
            $metric = $this->catalog->find($rule->metric);
            $value = $metric === null || $metric->kind() === Metric::EVENT ? '' : $metric->format($rule->threshold ?? 0);

            $entry = $this->history->add([
                'rule' => $rule->id,
                'rule_name' => $rule->name,
                'metric' => $rule->metric,
                'event' => History::TEST,
                'title' => $metric === null ? $rule->name : Message::title($rule, $metric, 'Sample item', $value),
                'items' => [['item' => 'Sample item', 'value' => $value]],
                'more' => 0,
                'text' => 'A test sent from the Alerts page.',
                'note' => $rule->note,
                'url' => Look::historyUrl($rule->id),
            ], $this->channels->resolve($rule->channels), now()->getTimestamp());

            $this->deliveries->deliver($entry, $this->channels->configured(), microtime(true), Deliveries::TEST_BUDGET);

            return $this->history->find((string) $entry['key']) ?? $entry;
        });
    }

    protected function reachable(): bool
    {
        try {
            return $this->storage->installed();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Tells an outside service the check ran, so it can warn when the
     * scheduler stops. It never stops the check.
     */
    protected function heartbeat(): void
    {
        $url = (string) $this->config->get('laralyze.alerts.heartbeat', '');

        if ($url === '') {
            return;
        }

        try {
            Http::timeout(self::HEARTBEAT_TIMEOUT)->get($url);
        } catch (Throwable) {
            // A missed ping is what the service watches for.
        }
    }
}
