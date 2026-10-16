<?php

namespace MohammedMojaly\Laralyze\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule as Allowed;
use Livewire\Attributes\Url;
use Livewire\Component;
use MohammedMojaly\Laralyze\Alerts\Alerts;
use MohammedMojaly\Laralyze\Alerts\Channels;
use MohammedMojaly\Laralyze\Alerts\Filter;
use MohammedMojaly\Laralyze\Alerts\History;
use MohammedMojaly\Laralyze\Alerts\Look;
use MohammedMojaly\Laralyze\Alerts\Metrics\Catalog;
use MohammedMojaly\Laralyze\Alerts\Metrics\Dimension;
use MohammedMojaly\Laralyze\Alerts\Metrics\Metric;
use MohammedMojaly\Laralyze\Alerts\Rule;
use MohammedMojaly\Laralyze\Alerts\Rules;
use MohammedMojaly\Laralyze\Alerts\States;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Laralyze;

/**
 * The Alerts page: rules with their state, the form to make or change
 * one, and the history of what was sent. Everyone who may see the
 * dashboard sees it; changing rules needs manageLaralyzeAlerts.
 */
class AlertsPage extends Component
{
    use Concerns\AuthorizesAccess;

    #[Url]
    public string $tab = 'rules';

    /**
     * History: only this rule's entries.
     */
    #[Url]
    public string $rule = '';

    /**
     * History: fired, recovered, reminder, test, or failed (a delivery that failed).
     */
    #[Url]
    public string $outcome = '';

    /**
     * The rule in the form: its id, 'new', or null while the form is closed.
     */
    public ?string $editing = null;

    /**
     * @var array<string, mixed>
     */
    public array $form = [];

    /**
     * What the last action did, e.g. a test send's result.
     */
    public string $notice = '';

    public function render(): View
    {
        $rules = app(Rules::class)->all();
        $catalog = app(Catalog::class);
        $history = app(History::class)->latest();
        $firing = app(States::class)->firing();
        $now = now()->getTimestamp();
        $lastFired = [];

        foreach ($history as $entry) {
            if ($entry['event'] === History::FIRED) {
                $lastFired[$entry['rule']] ??= (int) $entry['at'];
            }
        }

        $metric = $this->editing === null ? null : $catalog->find((string) ($this->form['metric'] ?? ''));

        return view('laralyze::alerts.page', [
            'rules' => $rules,
            'catalog' => $catalog,
            'available' => $catalog->available(),
            'channels' => app(Channels::class)->configured(),
            'states' => array_map(fn (Rule $rule) => match (true) {
                ! $rule->enabled => 'off',
                $rule->muted($now) => 'muted',
                isset($firing[$rule->id]) => 'firing',
                default => 'ok',
            }, $rules),
            'lastFired' => $lastFired,
            'entries' => array_values(array_filter($history, fn (array $entry) => ($this->rule === '' || $entry['rule'] === $this->rule) && match ($this->outcome) {
                '' => true,
                'failed' => History::failed($entry),
                default => $entry['event'] === $this->outcome,
            })),
            'canManage' => Gate::allows('manageLaralyzeAlerts'),
            'metric' => $metric,
            'dimension' => $this->dimension($metric),
            'preview' => $metric === null ? '' : $this->preview(),
            'suggestions' => $metric === null ? [] : $this->suggestions($metric),
        ]);
    }

    public function create(): void
    {
        $this->authorizeManaging();

        $this->editing = 'new';
        $this->form = $this->blank();
        $this->notice = '';
        $this->resetValidation();
    }

    public function edit(string $id): void
    {
        $this->authorizeManaging();

        $rule = app(Rules::class)->find($id);

        if ($rule === null) {
            return;
        }

        $list = ($this->dimension(app(Catalog::class)->find($rule->metric), $rule->filter?->by)->type ?? '') === 'list';

        $this->editing = $rule->id;
        $this->notice = '';
        $this->form = [
            'name' => $rule->name,
            'metric' => $rule->metric,
            'operator' => $rule->operator,
            'threshold' => $rule->threshold === null ? '' : (string) $rule->threshold,
            'window' => (string) $rule->window,
            'filter_by' => $rule->filter->by ?? '',
            'filter_operator' => $rule->filter->operator ?? 'contains',
            'filter_values' => $list ? '' : implode(', ', $rule->filter->values ?? []),
            'filter_choices' => $list ? ($rule->filter->values ?? []) : [],
            'mode' => $rule->mode,
            'channels' => $rule->channels,
            'remind' => (string) $rule->remind,
            'title' => $rule->title,
            'note' => $rule->note,
        ];
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->form = [];
        $this->resetValidation();
    }

    /**
     * Picking another metric or dimension starts the filter over.
     */
    public function updatedForm(mixed $value, string $key): void
    {
        if ($key === 'metric') {
            $this->form['filter_by'] = '';
        }

        if ($key === 'metric' || $key === 'filter_by') {
            $dimension = $this->dimension(app(Catalog::class)->find((string) ($this->form['metric'] ?? '')));
            $this->form['filter_operator'] = $dimension?->type === 'list' ? 'in' : 'contains';
            $this->form['filter_values'] = '';
            $this->form['filter_choices'] = [];
        }
    }

    public function save(): void
    {
        $this->authorizeManaging();

        $rules = app(Rules::class);
        $existing = in_array($this->editing, [null, 'new'], true) ? null : $rules->find((string) $this->editing);

        if ($existing === null && $rules->count() >= Rules::MAX) {
            $this->addError('form.name', 'Laralyze keeps up to '.Rules::MAX.' rules. Delete one to add another.');

            return;
        }

        $catalog = app(Catalog::class);
        $metric = $catalog->find((string) ($this->form['metric'] ?? ''));
        $dimension = $this->dimension($metric);

        $this->validate([
            'form.name' => ['required', 'string', 'max:100'],
            'form.metric' => ['required', Allowed::in(array_keys($catalog->available()))],
            'form.operator' => [Allowed::in(Rule::OPERATORS)],
            'form.threshold' => $metric?->kind() === Metric::EVENT ? ['nullable'] : ['required', 'numeric'],
            'form.window' => [Allowed::in(array_keys(Rule::WINDOWS))],
            'form.filter_by' => ['nullable', Allowed::in(array_keys($metric?->dimensions() ?? []))],
            'form.filter_operator' => [Allowed::in($dimension?->operators() ?? array_keys(Filter::OPERATORS))],
            'form.filter_values' => [$dimension !== null && $dimension->type !== 'list' ? 'required' : 'nullable', 'string', 'max:1000'],
            'form.filter_choices' => [$dimension?->type === 'list' ? 'required' : 'nullable', 'array'],
            'form.filter_choices.*' => [Allowed::in(array_keys($dimension->options ?? []))],
            'form.mode' => [Allowed::in([Rule::TOGETHER, Rule::EACH])],
            'form.channels' => ['array'],
            'form.channels.*' => [Allowed::in([Rule::ALL_CHANNELS, ...array_keys(app(Channels::class)->configured()), ...($existing->channels ?? [])])],
            'form.remind' => [Allowed::in(array_keys(Rule::REMINDERS))],
            'form.title' => ['nullable', 'string', 'max:150'],
            'form.note' => ['nullable', 'string', 'max:1000'],
        ], [
            'form.channels.*.in' => 'That channel isn\'t set in .env.',
        ], [
            'form.name' => 'name',
            'form.metric' => 'metric',
            'form.threshold' => 'threshold',
            'form.filter_values' => 'values',
            'form.filter_choices' => 'values',
            'form.title' => 'title',
            'form.note' => 'note',
        ]);

        $data = $this->ruleData();
        $rule = $existing === null ? Rule::fromArray((string) Str::ulid(), $data) : $existing->with($data);
        $rule = $rules->save($rule);

        $this->notice = $existing === null ? "Rule \"{$rule->name}\" created." : "Rule \"{$rule->name}\" saved.";
        $this->cancel();
    }

    public function toggle(string $id): void
    {
        $this->authorizeManaging();

        $rules = app(Rules::class);
        $rule = $rules->find($id);

        if ($rule !== null) {
            $rules->save($rule->with(['enabled' => ! $rule->enabled]));
        }
    }

    /**
     * @param  int  $minutes  0 unmutes; -1 mutes until unmuted.
     */
    public function mute(string $id, int $minutes): void
    {
        $this->authorizeManaging();

        $rules = app(Rules::class);
        $rule = $rules->find($id);

        if ($rule !== null) {
            $rules->save($rule->with(['muted_until' => match (true) {
                $minutes === 0 => null,
                $minutes < 0 => Rule::MUTED_FOREVER,
                default => now()->getTimestamp() + $minutes * 60,
            }]));
        }
    }

    public function delete(string $id): void
    {
        $this->authorizeManaging();

        app(Rules::class)->delete($id);

        if ($this->editing === $id) {
            $this->cancel();
        }
    }

    public function test(string $id): void
    {
        $this->authorizeManaging();

        $rule = app(Rules::class)->find($id);

        if ($rule === null) {
            return;
        }

        $deliveries = (array) (app(Alerts::class)->test($rule)['deliveries'] ?? []);
        $labels = app(Channels::class)->configured();

        $this->notice = $deliveries === []
            ? 'This rule has no channel to send to. Set one in .env, then pick it in the rule.'
            : implode(' ', array_map(
                fn (string $channel, array $delivery) => ($labels[$channel] ?? $channel).': '.match ($delivery['status']) {
                    History::SENT => 'sent.',
                    History::PENDING => 'will retry.',
                    default => 'not sent ('.$delivery['error'].').',
                },
                array_keys($deliveries),
                $deliveries,
            ));
    }

    protected function authorizeManaging(): void
    {
        Gate::authorize('manageLaralyzeAlerts');
    }

    protected function dimension(?Metric $metric, ?string $by = null): ?Dimension
    {
        return $metric?->dimensions()[$by ?? (string) ($this->form['filter_by'] ?? '')] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function ruleData(): array
    {
        $metric = app(Catalog::class)->find((string) ($this->form['metric'] ?? ''));
        $dimension = $this->dimension($metric);
        $values = $dimension?->type === 'list'
            ? array_map(fn (mixed $value) => (string) $value, (array) ($this->form['filter_choices'] ?? []))
            : explode(',', (string) ($this->form['filter_values'] ?? ''));

        return [
            'name' => $this->form['name'] ?? '',
            'metric' => $this->form['metric'] ?? '',
            'operator' => $this->form['operator'] ?? '>',
            'threshold' => $this->form['threshold'] ?? null,
            'window' => (int) ($this->form['window'] ?? 300),
            'filter' => $dimension === null ? null : ['by' => $dimension->name, 'operator' => $this->form['filter_operator'] ?? 'contains', 'values' => $values],
            'mode' => $this->form['mode'] ?? Rule::TOGETHER,
            'channels' => array_values((array) ($this->form['channels'] ?? [])),
            'remind' => (int) ($this->form['remind'] ?? 0),
            'title' => (string) ($this->form['title'] ?? ''),
            'note' => (string) ($this->form['note'] ?? ''),
        ];
    }

    protected function preview(): string
    {
        return (string) app(Laralyze::class)->rescue(
            fn () => app(Laralyze::class)->ignore(fn () => app(Look::class)->preview(Rule::fromArray('preview', $this->ruleData()), now()->getTimestamp())),
            '',
        );
    }

    /**
     * @return array<string, string>
     */
    protected function suggestions(Metric $metric): array
    {
        $by = (string) ($this->form['filter_by'] ?? '');

        return $by === '' ? [] : (array) app(Laralyze::class)->rescue(fn () => $metric->suggestions(app(Storage::class), $by), []);
    }

    /**
     * @return array<string, mixed>
     */
    protected function blank(): array
    {
        $available = app(Catalog::class)->available();

        return [
            'name' => '',
            'metric' => isset($available['requests.error_rate']) ? 'requests.error_rate' : (string) array_key_first($available),
            'operator' => '>',
            'threshold' => '',
            'window' => '300',
            'filter_by' => '',
            'filter_operator' => 'contains',
            'filter_values' => '',
            'filter_choices' => [],
            'mode' => Rule::TOGETHER,
            'channels' => [Rule::ALL_CHANNELS],
            'remind' => '0',
            'title' => '',
            'note' => '',
        ];
    }
}
