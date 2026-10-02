@use('MohammedMojaly\Laralyze\Support\Format')
@use('MohammedMojaly\Laralyze\Recorders\Requests')
<x-laralyze::card :card="$this" title="Routes" :count="$routes->count()">
    <x-slot:actions>
        <x-laralyze::search placeholder="Search routes" />
    </x-slot:actions>

    @if ($routes->isEmpty())
        <x-laralyze::empty
            :title="$search === '' ? 'No requests in the '.$this->range()->label().'.' : 'No routes match “'.$search.'”.'"
            :hint="$search === '' ? 'Each route gets a row once it has been called.' : null"
        />
    @else
        <div class="lz-table-wrap">
            <table class="lz-table lz-table-links">
                <thead>
                    <tr>
                        <x-laralyze::sort-header :card="$this" column="key" :num="false">Route</x-laralyze::sort-header>
                        @foreach (Requests::STATUS_CLASSES as $class)
                            <x-laralyze::sort-header :card="$this" :column="$class">{{ $class }}</x-laralyze::sort-header>
                        @endforeach
                        <x-laralyze::sort-header :card="$this" column="count">Total</x-laralyze::sort-header>
                        <x-laralyze::sort-header :card="$this" column="avg">Avg</x-laralyze::sort-header>
                        <x-laralyze::sort-header :card="$this" column="p95">p95</x-laralyze::sort-header>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($routes as $route)
                        <tr wire:key="{{ md5($route->key) }}">
                            <td>
                                <a class="lz-row-link lz-route" href="{{ $this->groupUrl('requests', $route->key) }}">
                                    <span class="lz-method">{{ $route->method }}</span>
                                    <span @class(['lz-path', 'is-unmatched' => $route->path === Requests::UNMATCHED]) title="{{ $route->path }}">{{ $route->path }}</span>
                                </a>
                            </td>
                            <td class="lz-num">{{ Format::number($route->{'2xx'}) }}</td>
                            <td class="lz-num">{{ Format::number($route->{'3xx'}) }}</td>
                            <td @class(['lz-num', 'lz-warn' => $route->{'4xx'} > 0])>{{ Format::number($route->{'4xx'}) }}</td>
                            <td @class(['lz-num', 'lz-bad' => $route->{'5xx'} > 0])>{{ Format::number($route->{'5xx'}) }}</td>
                            <td class="lz-num lz-strong">{{ Format::number($route->count) }}</td>
                            <td class="lz-num">{{ Format::duration($route->avg) }}</td>
                            <td class="lz-num">{{ Format::duration($route->p95) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-laralyze::card>
