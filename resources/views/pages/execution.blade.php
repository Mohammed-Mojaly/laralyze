@use('MohammedMojaly\Laralyze\Support\Format')
@use('MohammedMojaly\Laralyze\Support\Sql')
@php
    $events = $execution->events;
    $total = max($execution->duration, ...array_map(fn (array $event) => (float) $event[1] + (float) ($event[2] ?? 0), $events ?: [[0, 0, 0]]));
    $total = $total > 0 ? $total : 1;
    $recorded = array_sum(array_filter($execution->counts, fn ($count, $kind) => $kind !== 'memory', ARRAY_FILTER_USE_BOTH));
    $kinds = [
        'query' => 'Queries', 'cache' => 'Cache', 'http' => 'Outgoing requests', 'mail' => 'Mail',
        'notification' => 'Notifications', 'job' => 'Jobs queued', 'log' => 'Logs', 'exception' => 'Exceptions',
    ];
    $started = now()->setTimestamp($execution->started_at);
    $repeats = array_count_values(array_map(fn (array $event) => $event[0] === 'query' ? (string) $event[3] : '', $events));
    $groupUrl = fn (string $pageKey, string $key) => route('laralyze.group', ['page' => $pageKey, 'group' => hash('xxh128', $key)]);
@endphp
<x-laralyze::page :title="$execution->name" :page="$page?->key" mono>
    <x-laralyze::card title="Info" cols="5">
        <dl class="lz-info">
            <div><dt>Type</dt><dd><span class="lz-badge">{{ $execution->type }}</span></dd></div>
            <div><dt>Status</dt><dd><x-laralyze::status :execution="$execution" /></dd></div>
            <div><dt>Duration</dt><dd class="lz-strong">{{ Format::duration($execution->duration) }}</dd></div>
            <div><dt>Started</dt><dd><span title="{{ $started->toIso8601String() }}">{{ $started->format('M j, H:i:s') }}</span> · {{ $started->diffForHumans() }}</dd></div>
            <div><dt>User</dt><dd>
                @if ($execution->user_id === null)
                    {{ $execution->type === 'request' ? 'Guest' : '—' }}
                @else
                    <a href="{{ $groupUrl('users', $execution->user_id) }}">{{ $user['name'] ?? $execution->user_id }}</a>
                @endif
            </dd></div>
            <div><dt>Server</dt><dd>{{ $execution->server }}</dd></div>
            <div><dt>Peak memory</dt><dd>{{ Format::bytes($execution->counts['memory'] ?? null) }}</dd></div>
            @if ($parent)
                <div><dt>Queued by</dt><dd><a href="{{ route('laralyze.execution', ['execution' => $parent->uuid]) }}"><span class="lz-badge">{{ $parent->type }}</span> <span class="lz-mono">{{ $parent->name }}</span></a></dd></div>
            @endif
        </dl>
    </x-laralyze::card>

    <x-laralyze::card title="Inside it" cols="7">
        <dl class="lz-tally">
            @foreach ($kinds as $kind => $label)
                <div @class(['is-zero' => ($execution->counts[$kind] ?? 0) == 0, 'lz-bad' => $kind === 'exception' && ($execution->counts[$kind] ?? 0) > 0])>
                    <dt>{{ $label }}</dt>
                    <dd>{{ Format::number($execution->counts[$kind] ?? 0) }}</dd>
                </div>
            @endforeach
        </dl>
    </x-laralyze::card>

    <x-laralyze::card title="Timeline" :count="count($events)" cols="full">
        @if ($events === [])
            <x-laralyze::empty title="Nothing happened inside it." hint="Queries, cache calls, outgoing requests, mail, notifications, queued jobs, logs and exceptions show here in order." />
        @else
            @if (count($events) < $recorded)
                <p class="lz-note">Showing the first {{ Format::number(count($events)) }} of {{ Format::number($recorded) }} events. The counts above include them all.</p>
            @endif

            <ol class="lz-timeline">
                @foreach ($events as [$kind, $at, $ms, $label, $detail, $link])
                    <li @class(['lz-event', 'lz-event-'.$kind])>
                        <span class="lz-event-at">{{ Format::duration($at) }}</span>
                        <span class="lz-event-kind">{{ $kind }}</span>
                        <span class="lz-event-label">
                            @switch($kind)
                                @case('query')
                                    @if (($repeats[$label] ?? 0) >= \MohammedMojaly\Laralyze\Recorders\Traces::REPEATS)
                                        <a class="lz-badge lz-badge-bad" href="{{ route('laralyze.page', ['page' => 'findings']) }}" title="This query ran {{ $repeats[$label] }} times here">×{{ $repeats[$label] }}</a>
                                    @endif
                                    <code>{{ Sql::highlight(\Illuminate\Support\Str::limit($label, 400)) }}</code>
                                    @break
                                @case('exception')
                                    <a href="{{ route('laralyze.group', ['page' => 'exceptions', 'group' => $link]) }}"><x-laralyze::class-name :name="$label" /></a>
                                    <span class="lz-sub">{{ $detail }}</span>
                                    @break
                                @case('cache')
                                    <span @class(['lz-badge', 'lz-badge-warn' => $detail === 'miss'])>{{ $detail }}</span> <span class="lz-mono">{{ $label }}</span>
                                    @break
                                @case('http')
                                    <span class="lz-mono">{{ $label }}</span> <span @class(['lz-badge', 'lz-badge-bad' => $detail === 'failed' || (int) $detail >= 500, 'lz-badge-warn' => (int) $detail >= 400 && (int) $detail < 500])>{{ $detail }}</span>
                                    @break
                                @case('log')
                                    <span @class(['lz-badge', 'lz-badge-bad' => in_array($detail, ['error', 'critical', 'alert', 'emergency'], true), 'lz-badge-warn' => $detail === 'warning'])>{{ $detail }}</span> {{ \Illuminate\Support\Str::limit($label, 300) }}
                                    @break
                                @default
                                    <x-laralyze::class-name :name="$label" />@if ($detail) <span class="lz-sub">{{ $detail }}</span>@endif
                            @endswitch
                        </span>
                        <span class="lz-event-bar" aria-hidden="true"><span style="left: {{ round(min(100, $at / $total * 100), 2) }}%; width: {{ round(max(0.4, min(100 - $at / $total * 100, ($ms ?? 0) / $total * 100)), 2) }}%"></span></span>
                        <span class="lz-event-ms">{{ $ms === null ? '' : Format::duration($ms) }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-laralyze::card>

    @if ($related->isNotEmpty())
        <x-laralyze::card :title="$parent ? 'Same trace' : 'Jobs it queued'" :count="$related->count()" cols="full">
            <x-laralyze::table class="lz-table-links">
                <x-slot:head>
                    <th scope="col">What</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="lz-num">Duration</th>
                    <th scope="col">When</th>
                </x-slot:head>
                @foreach ($related as $other)
                    <tr>
                        <td><a class="lz-row-link" href="{{ route('laralyze.execution', ['execution' => $other->uuid]) }}"><span class="lz-badge">{{ $other->type }}</span> <span class="lz-mono">{{ $other->name }}</span></a></td>
                        <td><x-laralyze::status :execution="$other" /></td>
                        <td class="lz-num">{{ Format::duration($other->duration) }}</td>
                        <td><x-laralyze::ago :at="$other->started_at" /></td>
                    </tr>
                @endforeach
            </x-laralyze::table>
        </x-laralyze::card>
    @endif
</x-laralyze::page>
