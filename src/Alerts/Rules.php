<?php

namespace MohammedMojaly\Laralyze\Alerts;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use MohammedMojaly\Laralyze\Alerts\Metrics\Catalog;
use MohammedMojaly\Laralyze\Alerts\Metrics\DatabaseProblems;
use MohammedMojaly\Laralyze\Contracts\Storage;

/**
 * Alert rules, kept as values that are never trimmed.
 */
final class Rules
{
    public const TYPE = 'alert_rule';

    /**
     * One-time setup, like the default rules having been made.
     */
    public const SETUP = 'alert_setup';

    public const MAX = 100;

    public function __construct(private Storage $storage, private States $states, private Repository $config, private Catalog $catalog) {}

    /**
     * @return array<string, Rule> By id, sorted by name.
     */
    public function all(): array
    {
        $rules = [];

        foreach ($this->storage->values(self::TYPE) as $row) {
            $rule = Rule::fromJson((string) $row->key, (string) $row->value);

            if ($rule !== null) {
                $rules[$rule->id] = $rule;
            }
        }

        uasort($rules, fn (Rule $a, Rule $b) => strnatcasecmp($a->name, $b->name));

        return $rules;
    }

    public function find(string $id): ?Rule
    {
        $row = $this->storage->values(self::TYPE, [$id])->first();

        return $row === null ? null : Rule::fromJson($id, (string) $row->value);
    }

    public function count(): int
    {
        return $this->storage->values(self::TYPE)->count();
    }

    public function save(Rule $rule): Rule
    {
        $now = now()->getTimestamp();
        $rule = $rule->with(['created_at' => $rule->createdAt ?: $now, 'updated_at' => $now]);
        $old = $this->find($rule->id);

        $this->storage->put(self::TYPE, $rule->id, (string) json_encode($rule->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // A rule turned off holds nothing "firing", and one that now watches
        // something else starts over. A new threshold keeps what's firing.
        $watches = fn (Rule $rule) => [$rule->metric, $rule->mode, $rule->filter?->toArray()];

        if (! $rule->enabled || ($old !== null && $watches($old) !== $watches($rule))) {
            $this->states->forgetRule($rule->id);
        }

        return $rule;
    }

    public function delete(string $id): void
    {
        $this->storage->forget(self::TYPE, $id);
        $this->states->forgetRule($id);
    }

    /**
     * The default rules, made on the first look or the first visit to the
     * Alerts page, from the old `alerts.rules` config, and never again: a
     * deleted one stays gone.
     */
    public function createDefaults(): void
    {
        if ($this->storage->values(self::SETUP, ['defaults'])->isNotEmpty()) {
            return;
        }

        foreach (self::defaults((array) $this->config->get('laralyze.alerts.rules', [])) as $rule) {
            // AI cost needs laravel/ai.
            if ($this->catalog->find($rule->metric) !== null) {
                $this->save($rule);
            }
        }

        $this->storage->put(self::SETUP, 'defaults', '1');
    }

    /**
     * @param  array<string, mixed>  $config  The old alerts.rules config. A rule turned off there (false or null) is made disabled.
     * @return list<Rule>
     */
    public static function defaults(array $config): array
    {
        $rule = fn (string $name, array $data) => Rule::fromArray((string) Str::ulid(), ['name' => $name, 'channels' => [Rule::ALL_CHANNELS], ...$data]);
        $setting = fn (string $key, mixed $default) => array_key_exists($key, $config) ? $config[$key] : $default;
        $errorRate = $setting('error_rate', 5);
        $failedJobs = $setting('failed_jobs', 10);

        return [
            $rule('New or returning exception', ['metric' => 'exceptions.new', 'enabled' => (bool) $setting('exceptions', true)]),
            $rule('Error rate', ['metric' => 'requests.error_rate', 'operator' => '>', 'threshold' => is_numeric($errorRate) ? $errorRate : 5, 'window' => 300, 'enabled' => is_numeric($errorRate)]),
            $rule('Failed jobs', ['metric' => 'jobs.failed', 'operator' => '>=', 'threshold' => is_numeric($failedJobs) ? $failedJobs : 10, 'window' => 300, 'enabled' => is_numeric($failedJobs)]),
            $rule('Database health problem', ['metric' => 'database.problem', 'filter' => ['by' => 'kind', 'operator' => 'in', 'values' => DatabaseProblems::DEFAULT_KINDS]]),
            $rule('Scheduled task failed', ['metric' => 'scheduled.failed']),
            $rule('Server stopped reporting', ['metric' => 'servers.stopped', 'operator' => '>=', 'threshold' => 5, 'mode' => Rule::EACH]),
            $rule('Disk almost full', ['metric' => 'servers.disk', 'operator' => '>=', 'threshold' => 90, 'mode' => Rule::EACH]),
            // Ready to turn on, to show what else a rule can watch.
            $rule('Slow requests', ['metric' => 'requests.p95', 'operator' => '>', 'threshold' => 1000, 'window' => 300, 'enabled' => false]),
            $rule('Jobs waiting in a queue', ['metric' => 'queues.waiting', 'operator' => '>=', 'threshold' => 100, 'enabled' => false]),
            $rule('Errors in the logs', ['metric' => 'logs.entries', 'operator' => '>=', 'threshold' => 10, 'window' => 300, 'filter' => ['by' => 'level', 'operator' => 'in', 'values' => ['emergency', 'alert', 'critical', 'error']], 'enabled' => false]),
            $rule('Scheduled task overdue', ['metric' => 'scheduled.overdue', 'enabled' => false]),
            $rule('High CPU', ['metric' => 'servers.cpu', 'operator' => '>', 'threshold' => 90, 'window' => 900, 'mode' => Rule::EACH, 'enabled' => false]),
            $rule('AI cost per day', ['metric' => 'ai.cost', 'operator' => '>', 'threshold' => 10, 'window' => 86_400, 'enabled' => false]),
        ];
    }
}
