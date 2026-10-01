@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Queries">
    <x-slot:actions>
        <x-laralyze::segmented :options="['sum' => 'Total time', 'count' => 'Count', 'avg' => 'Average']" :value="$sort" model="sort" label="Sort queries by" />
    </x-slot:actions>

    @if ($queries->isEmpty())
        <x-laralyze::empty
            :title="'No queries in the '.$this->range()->label().'.'"
            hint="Each distinct query gets a row. Lists like IN (1, 2, 3) are folded together."
        />
    @else
        <x-laralyze::table>
            <x-slot:head>
                <th scope="col">Query</th>
                <th scope="col" class="lz-num">Count</th>
                <th scope="col" class="lz-num">Total</th>
                <th scope="col" class="lz-num">Avg</th>
                <th scope="col" class="lz-num">p95</th>
                <th scope="col" class="lz-num">Slowest</th>
            </x-slot:head>

            @foreach ($queries as $query)
                <tr wire:key="{{ md5($query->key) }}">
                    <td class="lz-wrap"><code class="lz-sql" title="{{ $query->key }}">{{ $query->key }}</code></td>
                    <td class="lz-num">{{ Format::number($query->count) }}</td>
                    <td class="lz-num lz-strong">{{ Format::duration($query->sum) }}</td>
                    <td class="lz-num">{{ Format::duration($query->avg) }}</td>
                    <td class="lz-num">{{ Format::duration($query->p95) }}</td>
                    <td class="lz-num">{{ Format::duration($query->max) }}</td>
                </tr>
            @endforeach
        </x-laralyze::table>
    @endif
</x-laralyze::card>
