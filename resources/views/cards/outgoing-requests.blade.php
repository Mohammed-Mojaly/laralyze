@use('Laralyze\Support\Chart')
@use('Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Outgoing requests">
    @if ($total > 0)
        <div class="lz-figures">
            <x-laralyze::figure :value="Format::number($total)" label="requests" />
            <x-laralyze::legend :items="$statuses" :total="$total" />
        </div>

        <x-laralyze::bars :chart="new Chart($series, $this->range())" />

        <x-laralyze::table>
            <x-slot:head>
                <th scope="col">URL</th>
                <th scope="col" class="lz-num">2xx</th>
                <th scope="col" class="lz-num">4xx</th>
                <th scope="col" class="lz-num">5xx</th>
                <th scope="col" class="lz-num">No response</th>
                <th scope="col" class="lz-num">Avg</th>
                <th scope="col" class="lz-num">p95</th>
            </x-slot:head>

            @foreach ($requests as $request)
                <tr wire:key="{{ md5($request->method.$request->url) }}">
                    <td>
                        <div class="lz-route">
                            <span class="lz-method">{{ $request->method }}</span>
                            <span class="lz-path" title="{{ $request->url }}">{{ $request->url }}</span>
                        </div>
                    </td>
                    <td class="lz-num">{{ Format::number($request->{'2xx'} + $request->{'3xx'}) }}</td>
                    <td @class(['lz-num', 'lz-warn' => $request->{'4xx'} > 0])>{{ Format::number($request->{'4xx'}) }}</td>
                    <td @class(['lz-num', 'lz-bad' => $request->{'5xx'} > 0])>{{ Format::number($request->{'5xx'}) }}</td>
                    <td @class(['lz-num', 'lz-bad' => $request->failed > 0])>{{ Format::number($request->failed) }}</td>
                    <td class="lz-num">{{ Format::duration($request->avg) }}</td>
                    <td class="lz-num">{{ Format::duration($request->p95) }}</td>
                </tr>
            @endforeach
        </x-laralyze::table>
    @else
        <x-laralyze::empty
            :title="'No outgoing requests in the '.$this->range()->label().'.'"
            hint="Calls made with Laravel's HTTP client appear here, including ones that never got a response."
        />
    @endif
</x-laralyze::card>
