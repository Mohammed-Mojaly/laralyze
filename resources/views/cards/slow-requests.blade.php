@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Slow requests">
    @if ($requests->isEmpty())
        <x-laralyze::empty
            :title="'No slow requests in the '.$this->range()->label().'.'"
            hint="A request counts as slow once it takes longer than its threshold in config/laralyze.php."
        />
    @else
        <div class="lz-table-wrap">
            <table class="lz-table">
                <thead>
                    <tr>
                        <th scope="col">Route</th>
                        <th scope="col" class="lz-num">Count</th>
                        <th scope="col" class="lz-num">Slowest</th>
                        <th scope="col" class="lz-num">Threshold</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($requests as $request)
                        <tr wire:key="{{ $request->key }}">
                            <td>
                                <div class="lz-route">
                                    <span class="lz-method">{{ $request->method }}</span>
                                    <span @class(['lz-path', 'is-unmatched' => $request->path === \MohammedMojaly\Laralyze\Recorders\Requests::UNMATCHED]) title="{{ $request->path }}">{{ $request->path }}</span>
                                </div>
                            </td>
                            <td class="lz-num lz-strong">{{ Format::number($request->count) }}</td>
                            <td class="lz-num lz-bad">{{ Format::duration($request->max) }}</td>
                            <td class="lz-num lz-muted">{{ Format::duration($request->threshold) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-laralyze::card>
