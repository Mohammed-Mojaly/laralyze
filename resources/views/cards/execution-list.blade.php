@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" :title="$title" :count="$executions->count()">
    <x-slot:actions>
        @if ($speeds !== [])
            <x-laralyze::segmented :options="['all' => 'Any speed', ...(isset($speeds['avg']) ? ['avg' => '≥ avg'] : []), ...(isset($speeds['p95']) ? ['p95' => '≥ p95'] : [])]" :value="$speed" model="speed" label="Speed" />
        @endif
        <x-laralyze::segmented :options="['all' => 'All', 'ok' => $type === 'job' ? 'Processed' : 'Successful', 'failed' => 'Failed']" :value="$status" model="status" label="Status" />
        <x-laralyze::segmented :options="['slowest' => 'Slowest', 'recent' => 'Recent']" :value="$order" model="order" label="Order" />
    </x-slot:actions>

    @if ($executions->isEmpty())
        <x-laralyze::empty
            :title="'Nothing kept in the '.$this->range()->label().($status !== 'all' || $speed !== 'all' ? ' with these filters' : '').'.'"
            :hint="$rate < 1 ? 'Slow, failed and throwing ones are always kept with their timeline; the rest are sampled at '.$rate.' (LARALYZE_TRACES_SAMPLE_RATE).' : 'Each one is kept with its timeline.'"
        />
    @else
        <x-laralyze::table class="lz-table-links">
            <x-slot:head>
                <th scope="col">When</th>
                @if ($type === 'command')
                    <th scope="col">Command</th>
                @elseif ($name === '')
                    <th scope="col">{{ $type === '' ? 'What' : ucfirst($type) }}</th>
                @endif
                @if ($type === 'job')
                    <th scope="col">Connection</th>
                    <th scope="col">Queue</th>
                    <th scope="col" class="lz-num">Attempt</th>
                @endif
                <th scope="col">{{ $type === 'command' ? 'Exit code' : 'Status' }}</th>
                <th scope="col" class="lz-num">Duration</th>
                <th scope="col" class="lz-num">Queries</th>
                @if ($user === '' && $type !== 'job' && $type !== 'command')
                    <th scope="col">User</th>
                @endif
            </x-slot:head>

            @foreach ($executions as $execution)
                <tr wire:key="{{ $execution->uuid }}">
                    <td class="lz-nowrap">
                        <a class="lz-row-link" href="{{ route('laralyze.execution', ['execution' => $execution->uuid, 'period' => $this->period]) }}"><x-laralyze::ago :at="$execution->started_at" /></a>
                    </td>
                    @if ($type === 'command')
                        <td class="lz-wrap"><span class="lz-mono">{{ $execution->meta['line'] ?? $execution->name }}</span></td>
                    @elseif ($name === '')
                        <td class="lz-wrap">
                            @if ($type === '' || $exception !== '')<span class="lz-badge">{{ $execution->type }}</span> @endif
                            <span class="lz-mono">{{ $execution->name }}</span>
                        </td>
                    @endif
                    @if ($type === 'job')
                        <td>{{ $execution->meta['connection'] ?? '—' }}</td>
                        <td>{{ $execution->meta['queue'] ?? '—' }}</td>
                        <td class="lz-num">{{ $execution->meta['attempt'] ?? '—' }}</td>
                    @endif
                    <td class="lz-wrap">
                        <x-laralyze::status :execution="$execution" />
                        @if ($execution->failed && ! empty($execution->meta['error']))
                            <span class="lz-sub lz-bad">{{ $execution->meta['error'] }}</span>
                        @endif
                    </td>
                    <td class="lz-num lz-strong">{{ Format::duration($execution->duration) }}</td>
                    <td class="lz-num">{{ Format::number($execution->counts['query'] ?? 0) }}</td>
                    @if ($user === '' && $type !== 'job' && $type !== 'command')
                        <td>{{ $execution->user_id === null ? ($execution->type === 'request' ? 'Guest' : '—') : ($users[$execution->user_id] ?? $execution->user_id) }}</td>
                    @endif
                </tr>
            @endforeach
        </x-laralyze::table>

        @if ($current > 1 || $more)
            <nav class="lz-pager" aria-label="Pages">
                <button type="button" class="lz-button" wire:click="previousPage" @disabled($current <= 1)>‹ Previous</button>
                <span class="lz-muted">Page {{ $current }}</span>
                <button type="button" class="lz-button" wire:click="nextPage" @disabled(! $more)>Next ›</button>
            </nav>
        @endif

        @if ($rate < 1)
            <p class="lz-note lz-list-note">Slow, failed and throwing ones are always kept; the rest are sampled at {{ $rate }} (LARALYZE_TRACES_SAMPLE_RATE). The numbers on the other pages count every one.</p>
        @endif
    @endif
</x-laralyze::card>
