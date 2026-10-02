@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" :title="$title" :count="$executions->count()">
    <x-slot:actions>
        <x-laralyze::segmented :options="['slowest' => 'Slowest', 'recent' => 'Recent']" :value="$order" model="order" label="Order" />
    </x-slot:actions>

    @if ($executions->isEmpty())
        <x-laralyze::empty
            :title="'Nothing kept in the '.$this->range()->label().'.'"
            hint="Slow, failed and throwing ones are always kept with their timeline; the rest are sampled (LARALYZE_TRACES_SAMPLE_RATE)."
        />
    @else
        <x-laralyze::table class="lz-table-links">
            <x-slot:head>
                <th scope="col">When</th>
                @if ($name === '')
                    <th scope="col">{{ $type === '' ? 'What' : ucfirst($type) }}</th>
                @endif
                <th scope="col">Status</th>
                <th scope="col" class="lz-num">Duration</th>
                <th scope="col" class="lz-num">Queries</th>
                @if ($user === '')
                    <th scope="col">User</th>
                @endif
            </x-slot:head>

            @foreach ($executions as $execution)
                <tr wire:key="{{ $execution->uuid }}">
                    <td class="lz-nowrap">
                        <a class="lz-row-link" href="{{ route('laralyze.execution', ['execution' => $execution->uuid, 'period' => $this->period]) }}"><x-laralyze::ago :at="$execution->started_at" /></a>
                    </td>
                    @if ($name === '')
                        <td class="lz-wrap">
                            @if ($type === '' || $exception !== '')<span class="lz-badge">{{ $execution->type }}</span> @endif
                            <span class="lz-mono">{{ $execution->name }}</span>
                        </td>
                    @endif
                    <td><x-laralyze::status :execution="$execution" /></td>
                    <td class="lz-num lz-strong">{{ Format::duration($execution->duration) }}</td>
                    <td class="lz-num">{{ Format::number($execution->counts['query'] ?? 0) }}</td>
                    @if ($user === '')
                        <td>{{ $execution->user_id === null ? ($execution->type === 'request' ? 'Guest' : '—') : ($users[$execution->user_id] ?? $execution->user_id) }}</td>
                    @endif
                </tr>
            @endforeach
        </x-laralyze::table>
    @endif
</x-laralyze::card>
