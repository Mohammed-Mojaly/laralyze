@use('MohammedMojaly\Laralyze\Support\Format')
@use('MohammedMojaly\Laralyze\Support\Sql')
<x-laralyze::card :card="$this" title="Queries" :count="$queries->count()">
    <x-slot:actions>
        <x-laralyze::search placeholder="Search queries" />
    </x-slot:actions>

    @if ($queries->isEmpty())
        <x-laralyze::empty
            :title="$search === '' ? 'No queries in the '.$this->range()->label().'.' : 'No queries match “'.$search.'”.'"
            :hint="$search === '' ? 'Each distinct query gets a row. Lists like IN (1, 2, 3) are folded together.' : null"
        />
    @else
        <x-laralyze::table class="lz-table-links">
            <x-slot:head>
                <x-laralyze::sort-header :card="$this" column="key" :num="false">Query</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="count">Calls</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="sum">Total</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="avg">Avg</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="p95">p95</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="max">Slowest</x-laralyze::sort-header>
            </x-slot:head>

            @foreach ($queries as $query)
                <tr wire:key="{{ md5($query->key) }}">
                    <td class="lz-wrap">
                        <a class="lz-row-link" href="{{ $this->groupUrl('queries', $query->key) }}"><code class="lz-sql" title="{{ $query->key }}">{{ Sql::highlight($query->key) }}</code></a>
                    </td>
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
