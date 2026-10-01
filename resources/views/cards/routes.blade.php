@use('Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Routes">
    <x-slot:actions>
        <div class="lz-segmented" role="group" aria-label="Sort routes by">
            @foreach (['count' => 'Requests', 'avg' => 'Average', 'p95' => 'p95'] as $value => $label)
                <button type="button" wire:click="$set('sort', '{{ $value }}')" @class(['is-active' => $sort === $value]) aria-pressed="{{ $sort === $value ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>
    </x-slot:actions>

    @if ($routes->isEmpty())
        <x-laralyze::empty
            :title="'No requests in the '.$this->range()->label().'.'"
            hint="Each route gets a row once it has been called."
        />
    @else
        <div class="lz-table-wrap">
            <table class="lz-table">
                <thead>
                    <tr>
                        <th scope="col">Route</th>
                        <th scope="col" class="lz-num">2xx</th>
                        <th scope="col" class="lz-num">3xx</th>
                        <th scope="col" class="lz-num">4xx</th>
                        <th scope="col" class="lz-num">5xx</th>
                        <th scope="col" class="lz-num">Total</th>
                        <th scope="col" class="lz-num">Avg</th>
                        <th scope="col" class="lz-num">p95</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($routes as $route)
                        <tr wire:key="{{ $route->key }}">
                            <td>
                                <div class="lz-route">
                                    <span class="lz-method">{{ $route->method }}</span>
                                    <span @class(['lz-path', 'is-unmatched' => $route->path === \Laralyze\Recorders\Requests::UNMATCHED]) title="{{ $route->path }}">{{ $route->path }}</span>
                                </div>
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
