@use('MohammedMojaly\Laralyze\Support\Chart')
@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Outgoing requests" :count="$total > 0 ? $requests->count() : null">
    @if ($total > 0)
        <x-slot:actions>
            <x-laralyze::search placeholder="Search URLs" />
        </x-slot:actions>

        <div class="lz-figures">
            <x-laralyze::figure :value="Format::number($total)" label="requests" />
            <x-laralyze::legend :items="$statuses" :total="$total" />
        </div>

        <x-laralyze::bars :chart="new Chart($series, $this->range())" />

        @if ($requests->isEmpty())
            <x-laralyze::empty :title="'No URLs match “'.$search.'”.'" />
        @else
            <x-laralyze::table class="lz-table-links">
                <x-slot:head>
                    <x-laralyze::sort-header :card="$this" column="key" :num="false">URL</x-laralyze::sort-header>
                    <x-laralyze::sort-header :card="$this" column="ok">2xx</x-laralyze::sort-header>
                    <x-laralyze::sort-header :card="$this" column="4xx">4xx</x-laralyze::sort-header>
                    <x-laralyze::sort-header :card="$this" column="5xx">5xx</x-laralyze::sort-header>
                    <x-laralyze::sort-header :card="$this" column="failed">No response</x-laralyze::sort-header>
                    <x-laralyze::sort-header :card="$this" column="count">Total</x-laralyze::sort-header>
                    <x-laralyze::sort-header :card="$this" column="avg">Avg</x-laralyze::sort-header>
                    <x-laralyze::sort-header :card="$this" column="p95">p95</x-laralyze::sort-header>
                </x-slot:head>

                @foreach ($requests as $request)
                    <tr wire:key="{{ md5($request->key) }}">
                        <td>
                            <a class="lz-row-link lz-route" href="{{ $this->groupUrl('outgoing-requests', $request->key) }}">
                                <span class="lz-method">{{ $request->method }}</span>
                                <span class="lz-path" title="{{ $request->url }}">{{ $request->url }}</span>
                            </a>
                        </td>
                        <td class="lz-num">{{ Format::number($request->ok) }}</td>
                        <td @class(['lz-num', 'lz-warn' => $request->{'4xx'} > 0])>{{ Format::number($request->{'4xx'}) }}</td>
                        <td @class(['lz-num', 'lz-bad' => $request->{'5xx'} > 0])>{{ Format::number($request->{'5xx'}) }}</td>
                        <td @class(['lz-num', 'lz-bad' => $request->failed > 0])>{{ Format::number($request->failed) }}</td>
                        <td class="lz-num lz-strong">{{ Format::number($request->count) }}</td>
                        <td class="lz-num">{{ Format::duration($request->avg) }}</td>
                        <td class="lz-num">{{ Format::duration($request->p95) }}</td>
                    </tr>
                @endforeach
            </x-laralyze::table>
        @endif
    @else
        <x-laralyze::empty
            :title="'No outgoing requests in the '.$this->range()->label().'.'"
            hint="Calls made with Laravel's HTTP client appear here, including ones that never got a response."
        />
    @endif
</x-laralyze::card>
