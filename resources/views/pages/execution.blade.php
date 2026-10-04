@use('MohammedMojaly\Laralyze\Support\Format')
@use('MohammedMojaly\Laralyze\Support\Sql')
@use('Illuminate\Support\Str')
@php
    $events = $execution->events;
    $meta = $execution->meta;
    $stages = $meta['stages'] ?? [];
    $ends = array_map(fn (array $event) => (float) $event[1] + (float) ($event[2] ?? 0), $events);
    $total = max(1, $execution->duration, ...($ends ?: [0]), ...array_map(fn (array $stage) => (float) $stage[2], $stages ?: [['', 0, 0]]));
    $recorded = array_sum(array_filter($execution->counts, fn ($count, $kind) => $kind !== 'memory', ARRAY_FILTER_USE_BOTH));
    $kinds = [
        'query' => 'Queries', 'cache' => 'Cache', 'http' => 'Outgoing requests', 'mail' => 'Mail',
        'notification' => 'Notifications', 'job' => 'Jobs queued', 'log' => 'Logs', 'exception' => 'Exceptions',
        // Only apps that use laravel/ai have these.
        ...(isset($execution->counts['ai']) ? ['ai' => 'AI calls', 'tool' => 'AI tools'] : []),
    ];
    $prices = isset($execution->counts['ai']) ? app(\MohammedMojaly\Laralyze\Support\AiPrices::class) : null;
    $started = now()->setTimestamp($execution->started_at);
    $groupUrl = fn (string $pageKey, string $key) => route('laralyze.group', ['page' => $pageKey, 'group' => hash('xxh128', $key)]);
    // Only reads can be an N+1, the same as the detector.
    $repeats = array_count_values(array_map(fn (array $event) => $event[0] === 'query' && stripos((string) $event[3], 'select') === 0 ? (string) $event[3] : '', $events));
    $exceptions = array_values(array_filter($events, fn (array $event) => $event[0] === 'exception'));
    $logs = array_values(array_filter($events, fn (array $event) => $event[0] === 'log'));
    $bar = fn (float $at, ?float $ms) => 'left: '.round(min(100, $at / $total * 100), 2).'%; width: '.round(max(0.4, min(100 - $at / $total * 100, ($ms ?? 0) / $total * 100)), 2).'%';

    // Each event under the stage it happened in; events before the first stage stay on top.
    $sections = [['stage' => null, 'events' => []]];
    foreach ($stages as $stage) {
        $sections[] = ['stage' => $stage, 'events' => []];
    }
    foreach ($events as $event) {
        $index = 0;
        foreach ($stages as $i => [, $from]) {
            if ((float) $event[1] >= (float) $from) {
                $index = $i + 1;
            }
        }
        $sections[$index]['events'][] = $event;
    }
    $title = $execution->type === 'command' ? ($meta['line'] ?? $execution->name) : $execution->name;
    $attempt = $attempts->search(fn ($other) => $other->uuid === $execution->uuid);
@endphp
<x-laralyze::page :title="$title" :page="$page?->key" :ask="['execution', $execution->uuid]" mono>
    @if ($attempts->count() > 1)
        <nav class="lz-attempts lz-span-full" aria-label="Attempts">
            <span class="lz-muted">Attempt {{ $attempt === false ? '?' : $attempt + 1 }} of {{ $attempts->count() }}</span>
            @foreach ($attempts as $i => $other)
                <a @class(['lz-button', 'is-active' => $other->uuid === $execution->uuid]) href="{{ route('laralyze.execution', ['execution' => $other->uuid]) }}">#{{ $i + 1 }} <x-laralyze::status :execution="$other" /></a>
            @endforeach
        </nav>
    @endif

    @if ($execution->failed && $exceptions !== [])
        @php([, , , $class, $message, $hash] = $exceptions[0])
        <x-laralyze::card cols="full" class="lz-exception">
            <header class="lz-exception-head">
                <div class="lz-exception-tags"><span class="lz-badge lz-badge-bad">Failed</span></div>
                <a class="lz-button" href="{{ route('laralyze.group', ['page' => 'exceptions', 'group' => $hash]) }}">Stack trace and code</a>
            </header>
            <h2 class="lz-exception-class">{{ $class }}</h2>
            <p class="lz-exception-message">{{ $message }}</p>
        </x-laralyze::card>
    @endif

    <x-laralyze::card title="Info" cols="5">
        <dl class="lz-info">
            <div><dt>{{ $execution->type === 'command' ? 'Exit code' : 'Status' }}</dt><dd><x-laralyze::status :execution="$execution" /></dd></div>
            @if ($execution->type === 'job')
                @if (! empty($meta['queued_at']))
                    <div><dt>Queued</dt><dd>{{ now()->setTimestamp((int) $meta['queued_at'])->format('M j, H:i:s') }}</dd></div>
                @endif
                <div><dt>Started</dt><dd><span title="{{ $started->toIso8601String() }}">{{ $started->format('M j, H:i:s') }}</span> · {{ $started->diffForHumans() }}</dd></div>
                <div><dt>Connection</dt><dd>{{ $meta['connection'] ?? '—' }}</dd></div>
                <div><dt>Queue</dt><dd>{{ $meta['queue'] ?? '—' }}</dd></div>
                <div><dt>Attempt</dt><dd>{{ $meta['attempt'] ?? '—' }}</dd></div>
            @else
                <div><dt>Started</dt><dd><span title="{{ $started->toIso8601String() }}">{{ $started->format('M j, H:i:s') }}</span> · {{ $started->diffForHumans() }}</dd></div>
            @endif
            @if ($execution->type === 'request')
                <div><dt>User</dt><dd>
                    @if ($execution->user_id === null)
                        Guest
                    @else
                        <a href="{{ $groupUrl('users', $execution->user_id) }}">{{ $user['name'] ?? $execution->user_id }}</a>
                    @endif
                </dd></div>
            @endif
            <div><dt>Duration</dt><dd class="lz-strong">{{ Format::duration($execution->duration) }}</dd></div>
            <div><dt>Peak memory</dt><dd>{{ Format::bytes($execution->counts['memory'] ?? null) }}</dd></div>
            <div><dt>Server</dt><dd>{{ $execution->server }}</dd></div>
            @if ($parent)
                <div><dt>Queued by</dt><dd><a href="{{ route('laralyze.execution', ['execution' => $parent->uuid]) }}"><span class="lz-badge">{{ $parent->type }}</span> <span class="lz-mono">{{ $parent->type === 'command' ? ($parent->meta['line'] ?? $parent->name) : $parent->name }}</span></a></dd></div>
            @endif
        </dl>
    </x-laralyze::card>

    <x-laralyze::card title="Events" :count="$recorded" cols="7">
        <dl class="lz-info lz-info-2">
            @foreach ($kinds as $kind => $label)
                @php($count = $execution->counts[$kind] ?? 0)
                <div @class(['is-zero' => $count == 0])>
                    <dt>{{ $label }}</dt>
                    <dd @class(['lz-bad' => $kind === 'exception' && $count > 0])>
                        {{ Format::number($count) }} {{ $count == 1 ? 'event' : 'events' }}@isset($meta['ms'][$kind]) <span class="lz-muted">/ {{ Format::duration((float) $meta['ms'][$kind]) }}</span>@endisset
                    </dd>
                </div>
            @endforeach
        </dl>
    </x-laralyze::card>

    @if ($exceptions !== [])
        <x-laralyze::card title="Exceptions" :count="count($exceptions)" cols="full">
            <ul class="lz-list">
                @foreach ($exceptions as [, $at, , $class, $message, $hash])
                    <li>
                        <span class="lz-event-at">{{ Format::duration($at) }}</span>
                        <span><x-laralyze::class-name :name="$class" /> <span class="lz-sub">{{ $message }}</span></span>
                        <a class="lz-button" href="{{ route('laralyze.group', ['page' => 'exceptions', 'group' => $hash]) }}">View</a>
                    </li>
                @endforeach
            </ul>
        </x-laralyze::card>
    @endif

    @if ($logs !== [])
        <x-laralyze::card title="Logs" :count="count($logs)" cols="full">
            <ul class="lz-list">
                @foreach ($logs as [, $at, , $message, $level])
                    <li>
                        <span class="lz-event-at">{{ Format::duration($at) }}</span>
                        <span><span @class(['lz-badge', 'lz-badge-bad' => in_array($level, ['error', 'critical', 'alert', 'emergency'], true), 'lz-badge-warn' => $level === 'warning'])>{{ $level }}</span> {{ Str::limit((string) $message, 500) }}</span>
                    </li>
                @endforeach
            </ul>
        </x-laralyze::card>
    @endif

    <x-laralyze::card title="Timeline" :count="count($events)" cols="full">
        @if ($events === [] && $stages === [])
            <x-laralyze::empty title="Nothing happened inside it." hint="Queries, cache calls, outgoing requests, mail, notifications, queued jobs, logs, exceptions and AI calls show here in order." />
        @else
            @if (count($events) < $recorded)
                <p class="lz-note">Showing the first {{ Format::number(count($events)) }} of {{ Format::number($recorded) }} events. The counts above include them all.</p>
            @endif

            <ol class="lz-timeline">
                @foreach ($sections as $section)
                    @if ($section['stage'])
                        @php([$stageName, $from, $to] = $section['stage'])
                        <li class="lz-event lz-stage">
                            <span class="lz-event-at">{{ Format::duration((float) $from) }}</span>
                            <span class="lz-event-kind">{{ $stageName }}</span>
                            <span class="lz-event-label lz-muted">{{ count($section['events']) }} {{ count($section['events']) === 1 ? 'event' : 'events' }}</span>
                            <span class="lz-event-bar" aria-hidden="true"><span style="{{ $bar((float) $from, (float) $to - (float) $from) }}"></span></span>
                            <span class="lz-event-ms">{{ Format::duration((float) $to - (float) $from) }}</span>
                        </li>
                    @endif

                    @foreach ($section['events'] as [$kind, $at, $ms, $label, $detail, $link])
                        <li @class(['lz-event', 'lz-event-'.$kind, 'lz-in-stage' => $section['stage'] !== null])>
                            <span class="lz-event-at">{{ Format::duration($at) }}</span>
                            <span class="lz-event-kind">{{ $kind }}</span>
                            <span class="lz-event-label">
                                @switch($kind)
                                    @case('query')
                                        @if (($repeats[$label] ?? 0) >= \MohammedMojaly\Laralyze\Recorders\Traces::REPEATS)
                                            <a class="lz-badge lz-badge-bad" href="{{ route('laralyze.page', ['page' => 'findings']) }}" title="This query ran {{ $repeats[$label] }} times here">×{{ $repeats[$label] }}</a>
                                        @endif
                                        <code>{{ Sql::highlight(Str::limit($label, 400)) }}</code>
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
                                    @case('ai')
                                        @php([$provider, $model, $in, $out, $aiFailed] = array_pad((array) json_decode((string) $detail, true), 5, null))
                                        @php($cost = $prices?->cost((string) $provider, (string) $model, [(int) $in, (int) $out, 0, 0]))
                                        <a href="{{ $groupUrl('ai', $label) }}"><x-laralyze::class-name :name="$label" /></a>
                                        <span class="lz-with-mark lz-sub">{!! \MohammedMojaly\Laralyze\Support\Brands::svg((string) $provider, 'ai') !!}{{ $model }}</span>
                                        @if ($aiFailed)
                                            <span class="lz-badge lz-badge-bad">failed</span>
                                        @else
                                            <span class="lz-sub">{{ $out ? Format::number((int) $in).' → '.Format::number((int) $out) : Format::number((int) $in) }} tokens @if ($cost !== null)· {{ Format::money($cost) }}@endif</span>
                                        @endif
                                        @break
                                    @case('tool')
                                        <x-laralyze::class-name :name="$label" />@if ($detail) <span class="lz-badge lz-badge-bad">{{ $detail }}</span>@endif
                                        @break
                                    @case('log')
                                        <span @class(['lz-badge', 'lz-badge-bad' => in_array($detail, ['error', 'critical', 'alert', 'emergency'], true), 'lz-badge-warn' => $detail === 'warning'])>{{ $detail }}</span> {{ Str::limit((string) $label, 300) }}
                                        @break
                                    @default
                                        <x-laralyze::class-name :name="$label" />@if ($detail) <span class="lz-sub">{{ $detail }}</span>@endif
                                @endswitch
                            </span>
                            <span class="lz-event-bar" aria-hidden="true"><span style="{{ $bar((float) $at, $ms === null ? null : (float) $ms) }}"></span></span>
                            <span class="lz-event-ms">{{ $ms === null ? '' : Format::duration($ms) }}</span>
                        </li>
                    @endforeach
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
                        <td><a class="lz-row-link" href="{{ route('laralyze.execution', ['execution' => $other->uuid]) }}"><span class="lz-badge">{{ $other->type }}</span> <span class="lz-mono">{{ $other->type === 'command' ? ($other->meta['line'] ?? $other->name) : $other->name }}</span>@if (! empty($other->meta['attempt'])) <span class="lz-muted">attempt {{ $other->meta['attempt'] }}</span>@endif</a></td>
                        <td><x-laralyze::status :execution="$other" /></td>
                        <td class="lz-num">{{ Format::duration($other->duration) }}</td>
                        <td><x-laralyze::ago :at="$other->started_at" /></td>
                    </tr>
                @endforeach
            </x-laralyze::table>
        </x-laralyze::card>
    @endif
</x-laralyze::page>
