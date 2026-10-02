<?php

namespace MohammedMojaly\Laralyze\Dashboard;

use Illuminate\Contracts\Config\Repository;
use MohammedMojaly\Laralyze\Recorders;

/**
 * The sidebar: built-in pages whose recorders are enabled, plus the
 * app's own pages from config('laralyze.pages').
 */
class Pages
{
    public const HOME = 'dashboard';

    protected const SECTIONS = ['Activity', 'Application', 'Monitoring'];

    /**
     * @var array<string, Page>|null
     */
    protected ?array $pages = null;

    public function __construct(protected Repository $config) {}

    /**
     * @return array<string, Page>
     */
    public function all(): array
    {
        return $this->pages ??= $this->build();
    }

    public function find(string $key): ?Page
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Pages grouped by sidebar section, built-in sections first. Pages
     * without a section come first, under the '' key.
     *
     * @return array<string, list<Page>>
     */
    public function sections(): array
    {
        $sections = array_fill_keys(['', ...self::SECTIONS], []);

        foreach ($this->all() as $page) {
            $sections[$page->section ?? ''][] = $page;
        }

        return array_filter($sections);
    }

    /**
     * @return array<string, Page>
     */
    protected function build(): array
    {
        $definitions = $this->builtIn();

        foreach ((array) $this->config->get('laralyze.pages', []) as $key => $page) {
            if ($page === false) {
                unset($definitions[$key]);
            } elseif (is_array($page)) {
                $definitions[$key] = [...$definitions[$key] ?? [], ...$page];
            }
        }

        $pages = [];

        foreach ($definitions as $key => $page) {
            if (isset($page['recorder']) && ! $this->isRecorderEnabled($page['recorder'])) {
                continue;
            }

            if (! isset($page['view'])) {
                continue;
            }

            $pages[(string) $key] = new Page(
                key: (string) $key,
                label: (string) ($page['label'] ?? str((string) $key)->headline()),
                view: (string) $page['view'],
                section: isset($page['section']) ? (string) $page['section'] : null,
                icon: (string) ($page['icon'] ?? 'page'),
            );
        }

        return $pages;
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected function builtIn(): array
    {
        $page = fn (string $label, string $section, string $recorder, string $key) => [
            'label' => $label,
            'section' => $section,
            'icon' => $key,
            'recorder' => $recorder,
            'view' => "laralyze::pages.{$key}",
        ];

        return [
            self::HOME => ['label' => 'Dashboard', 'view' => 'laralyze::pages.dashboard', 'icon' => 'dashboard'],
            'requests' => $page('Requests', 'Activity', Recorders\Requests::class, 'requests'),
            'jobs' => $page('Jobs', 'Activity', Recorders\Jobs::class, 'jobs'),
            'commands' => $page('Commands', 'Activity', Recorders\Commands::class, 'commands'),
            'scheduled' => $page('Scheduled Tasks', 'Activity', Recorders\ScheduledTasks::class, 'scheduled'),
            'exceptions' => $page('Exceptions', 'Application', Recorders\Exceptions::class, 'exceptions'),
            'queries' => $page('Queries', 'Application', Recorders\Queries::class, 'queries'),
            'cache' => $page('Cache', 'Application', Recorders\Cache::class, 'cache'),
            'outgoing-requests' => $page('Outgoing Requests', 'Application', Recorders\OutgoingRequests::class, 'outgoing-requests'),
            'mail' => $page('Mail', 'Application', Recorders\Mail::class, 'mail'),
            'notifications' => $page('Notifications', 'Application', Recorders\Notifications::class, 'notifications'),
            'visits' => $page('Visits', 'Monitoring', Recorders\Visits::class, 'visits'),
            'users' => $page('Users', 'Monitoring', Recorders\Users::class, 'users'),
            'logs' => $page('Logs', 'Monitoring', Recorders\Logs::class, 'logs'),
            'servers' => $page('Servers', 'Monitoring', Recorders\Servers::class, 'servers'),
        ];
    }

    /**
     * Subclasses count too, so swapping in your own recorder keeps its page.
     */
    public function isRecorderEnabled(string $recorder): bool
    {
        foreach ((array) $this->config->get('laralyze.recorders', []) as $class => $config) {
            if (is_a((string) $class, $recorder, true) && (($config['enabled'] ?? true) !== false)) {
                return true;
            }
        }

        return false;
    }
}
