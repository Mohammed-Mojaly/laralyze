@use('MohammedMojaly\Laralyze\Alerts\History')
@use('MohammedMojaly\Laralyze\Alerts\Rule')
@use('MohammedMojaly\Laralyze\Alerts\Rules')
@use('MohammedMojaly\Laralyze\Alerts\Metrics\Metric')
@php
    $events = [History::FIRED => 'Fired', History::RECOVERED => 'Back to normal', History::REMINDER => 'Reminder', History::TEST => 'Test'];
@endphp
<div class="lz-span-full lz-alerts-page">
    <div class="lz-alerts-bar">
        <div class="lz-segmented" role="tablist" aria-label="Alerts">
            <button type="button" role="tab" wire:click="$set('tab', 'rules')" @class(['is-active' => $tab === 'rules']) aria-selected="{{ $tab === 'rules' ? 'true' : 'false' }}">Rules <span class="lz-count">{{ count($rules) }}</span></button>
            <button type="button" role="tab" wire:click="$set('tab', 'history')" @class(['is-active' => $tab === 'history']) aria-selected="{{ $tab === 'history' ? 'true' : 'false' }}">History</button>
        </div>

        @if ($tab === 'rules' && $canManage && $editing === null)
            <button type="button" class="lz-button lz-button-primary" wire:click="create" @disabled(count($rules) >= Rules::MAX)>New rule</button>
        @endif
    </div>

    @if ($notice !== '')
        <p class="lz-alerts-notice" role="status">{{ $notice }}</p>
    @endif

    @if ($channels === [])
        <div class="lz-alert lz-alert-warn">
            <p class="lz-alert-title">No channel is set, so alerts are only kept here and in the bell.</p>
            <p class="lz-alert-hint">Add one to your .env: LARALYZE_ALERTS_MAIL, LARALYZE_ALERTS_SLACK_WEBHOOK, LARALYZE_ALERTS_DISCORD_WEBHOOK, LARALYZE_ALERTS_TELEGRAM_TOKEN with LARALYZE_ALERTS_TELEGRAM_CHATS, or LARALYZE_ALERTS_WEBHOOK_URL.</p>
        </div>
    @endif

    @if ($editing !== null)
        @include('laralyze::alerts.form')
    @elseif ($tab === 'rules')
        <x-laralyze::card title="Rules">
            @if ($rules === [])
                <x-laralyze::empty title="No rules yet." hint="A rule watches one thing Laralyze measures and tells your channels when it starts and when it's back to normal." />
            @else
                <div class="lz-table-wrap">
                    <table class="lz-table lz-rules">
                        <thead>
                            <tr>
                                <th>Rule</th>
                                <th>Condition</th>
                                <th>Channels</th>
                                <th>State</th>
                                <th>Last fired</th>
                                @if ($canManage)
                                    <th><span class="lz-offscreen">Actions</span></th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rules as $id => $alertRule)
                                @php($ruleMetric = $catalog->find($alertRule->metric))
                                <tr wire:key="rule-{{ $id }}">
                                    <td class="lz-strong">
                                        {{ $alertRule->name }}
                                        @if ($alertRule->note !== '')
                                            <span class="lz-rule-note">{{ \Illuminate\Support\Str::limit($alertRule->note, 80) }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        {{ $ruleMetric ? $ruleMetric->area.' · '.$ruleMetric->label : $alertRule->metric }}
                                        @if ($ruleMetric && $ruleMetric->kind() !== Metric::EVENT)
                                            {{ $alertRule->operator }} {{ $ruleMetric->format($alertRule->threshold) }}
                                            @if ($ruleMetric->kind() === Metric::NUMBER)
                                                <span class="lz-muted">over {{ Rule::WINDOWS[$alertRule->window] }}</span>
                                            @endif
                                        @endif
                                        @if ($alertRule->filter)
                                            <span class="lz-muted">· {{ $alertRule->filter->describe() }}</span>
                                        @endif
                                        @if ($alertRule->mode === Rule::EACH)
                                            <span class="lz-muted">· each separately</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if (in_array(Rule::ALL_CHANNELS, $alertRule->channels, true))
                                            All channels
                                        @else
                                            {{ implode(', ', array_map(fn ($channel) => $channels[$channel] ?? $channel.' (not set)', $alertRule->channels)) ?: 'None' }}
                                        @endif
                                    </td>
                                    <td><span @class(['lz-badge', 'lz-badge-bad' => $states[$id] === 'firing', 'lz-badge-warn' => $states[$id] === 'muted'])>{{ $states[$id] }}</span></td>
                                    <td>
                                        @isset($lastFired[$id])
                                            <x-laralyze::ago :at="$lastFired[$id]" />
                                        @else
                                            <span class="lz-muted">Never</span>
                                        @endisset
                                    </td>
                                    @if ($canManage)
                                        <td class="lz-rule-actions">
                                            <button type="button" class="lz-button" wire:click="toggle('{{ $id }}')">{{ $alertRule->enabled ? 'Turn off' : 'Turn on' }}</button>
                                            @if ($alertRule->mutedUntil !== null)
                                                <button type="button" class="lz-button" wire:click="mute('{{ $id }}', 0)">Unmute</button>
                                            @else
                                                <div class="lz-dropdown" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                                                    <button type="button" class="lz-button" x-on:click="open = ! open" x-bind:aria-expanded="open">Mute ▾</button>
                                                    <div class="lz-dropdown-menu" x-show="open" x-cloak>
                                                        <button type="button" class="lz-option" wire:click="mute('{{ $id }}', 60)" x-on:click="open = false">For an hour</button>
                                                        <button type="button" class="lz-option" wire:click="mute('{{ $id }}', 1440)" x-on:click="open = false">For a day</button>
                                                        <button type="button" class="lz-option" wire:click="mute('{{ $id }}', -1)" x-on:click="open = false">Until unmuted</button>
                                                    </div>
                                                </div>
                                            @endif
                                            <button type="button" class="lz-button" wire:click="edit('{{ $id }}')">Edit</button>
                                            <button type="button" class="lz-button" wire:click="test('{{ $id }}')" wire:loading.attr="disabled" wire:target="test('{{ $id }}')">Test</button>
                                            <button type="button" class="lz-button lz-button-danger" wire:click="delete('{{ $id }}')" wire:confirm="Delete the rule &quot;{{ $alertRule->name }}&quot;?">Delete</button>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-laralyze::card>
    @else
        <x-laralyze::card title="History" :count="count($entries)">
            <x-slot:actions>
                @if ($rule !== '')
                    <button type="button" class="lz-button" wire:click="$set('rule', '')">{{ $rules[$rule]->name ?? 'One rule' }} ✕</button>
                @endif
                <x-laralyze::segmented :options="['' => 'All', History::FIRED => 'Fired', History::RECOVERED => 'Back to normal', History::TEST => 'Tests', 'failed' => 'Not delivered']" :value="$outcome" model="outcome" label="Outcome" />
            </x-slot:actions>

            @if ($entries === [])
                <x-laralyze::empty title="Nothing here yet." hint="Each message a rule sends is kept here for {{ (int) config('laralyze.retention', 30) }} days, with how each channel took it." />
            @else
                <ul class="lz-entries">
                    @foreach ($entries as $entry)
                        <li id="entry-{{ $entry['key'] }}" wire:key="entry-{{ $entry['key'] }}" @class(['lz-entry', 'is-'.$entry['event']])>
                            <div class="lz-entry-head">
                                <span @class(['lz-badge', 'lz-badge-bad' => $entry['event'] === History::FIRED])>{{ $events[$entry['event']] ?? $entry['event'] }}</span>
                                <span class="lz-entry-title">{{ $entry['title'] }}</span>
                                <span class="lz-entry-at"><x-laralyze::ago :at="(int) $entry['at']" /></span>
                            </div>

                            @if (count($entry['items'] ?? []) > 1 || ($entry['more'] ?? 0) > 0)
                                <ul class="lz-entry-items">
                                    @foreach ($entry['items'] as $item)
                                        <li>{{ $item['item'] }}@if ($item['value'] !== '')<span class="lz-muted">: {{ $item['value'] }}</span>@endif</li>
                                    @endforeach
                                    @if (($entry['more'] ?? 0) > 0)
                                        <li class="lz-muted">and {{ $entry['more'] }} more</li>
                                    @endif
                                </ul>
                            @endif

                            @if (($entry['text'] ?? '') !== '')
                                <p class="lz-entry-text">{{ $entry['text'] }}</p>
                            @endif

                            <p class="lz-entry-foot">
                                <button type="button" class="lz-link" wire:click="$set('rule', @js((string) $entry['rule']))">{{ $entry['rule_name'] }}</button>
                                @forelse ((array) ($entry['deliveries'] ?? []) as $channel => $delivery)
                                    <span @class(['lz-delivery', 'is-'.$delivery['status']])>
                                        {{ $channels[$channel] ?? $channel }}
                                        @if ($delivery['status'] === History::SENT)
                                            ✓
                                        @elseif ($delivery['status'] === History::PENDING)
                                            · retrying
                                        @else
                                            ✗ <span class="lz-delivery-error">{{ $delivery['error'] }}</span>
                                        @endif
                                    </span>
                                @empty
                                    <span class="lz-muted">No channel</span>
                                @endforelse
                            </p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-laralyze::card>
    @endif
</div>
