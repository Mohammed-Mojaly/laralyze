@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Agents" :count="$agents->count()">
    <x-slot:actions>
        <x-laralyze::search placeholder="Search agents" />
    </x-slot:actions>

    @if ($agents->isEmpty())
        <x-laralyze::empty :title="$search === '' ? 'No AI calls in the '.$this->range()->label().'.' : 'No agents match “'.$search.'”.'" />
    @else
        <x-laralyze::table class="lz-table-links">
            <x-slot:head>
                <x-laralyze::sort-header :card="$this" column="key" :num="false">Agent</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="count">Calls</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="input">Tokens in</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="output">Tokens out</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="avg">Avg</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="p95">p95</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="cost">Cost</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="failed">Failed</x-laralyze::sort-header>
            </x-slot:head>

            @foreach ($agents as $agent)
                <tr wire:key="{{ md5($agent->key) }}">
                    <td><a class="lz-row-link" href="{{ $this->groupUrl('ai', $agent->key) }}"><x-laralyze::class-name :name="$agent->key" /></a></td>
                    <td class="lz-num lz-strong">{{ Format::number($agent->count) }}</td>
                    <td class="lz-num">{{ Format::number($agent->input) }}</td>
                    <td @class(['lz-num', 'lz-muted' => $agent->output == 0])>{{ $agent->output == 0 ? '—' : Format::number($agent->output) }}</td>
                    <td class="lz-num">{{ Format::duration($agent->avg) }}</td>
                    <td class="lz-num">{{ Format::duration($agent->p95) }}</td>
                    <td @class(['lz-num', 'lz-muted' => $agent->cost === null])>{{ Format::money($agent->cost) }}</td>
                    <td @class(['lz-num', 'lz-bad' => $agent->failed > 0, 'lz-muted' => $agent->failed == 0])>{{ Format::number($agent->failed) }}</td>
                </tr>
            @endforeach
        </x-laralyze::table>
    @endif
</x-laralyze::card>
