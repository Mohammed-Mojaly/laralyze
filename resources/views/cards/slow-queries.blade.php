@use('Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Slow queries">
    @if ($queries->isEmpty())
        <x-laralyze::empty
            :title="'No slow queries in the '.$this->range()->label().'.'"
            hint="A query counts as slow once it takes longer than its threshold in config/laralyze.php."
        />
    @else
        <x-laralyze::table>
            <x-slot:head>
                <th scope="col">Query</th>
                <th scope="col" class="lz-num">Count</th>
                <th scope="col" class="lz-num">Slowest</th>
                <th scope="col" class="lz-num">Threshold</th>
            </x-slot:head>

            @foreach ($queries as $query)
                <tr wire:key="{{ md5($query->key) }}">
                    <td class="lz-wrap">
                        <code class="lz-sql" title="{{ $query->sql }}">{{ $query->sql }}</code>
                        @if ($query->location)
                            <span class="lz-sub lz-mono">{{ $query->location }}</span>
                        @endif
                    </td>
                    <td class="lz-num lz-strong">{{ Format::number($query->count) }}</td>
                    <td class="lz-num lz-bad">{{ Format::duration($query->max) }}</td>
                    <td class="lz-num lz-muted">{{ Format::duration($query->threshold) }}</td>
                </tr>
            @endforeach
        </x-laralyze::table>
    @endif
</x-laralyze::card>
