@use('MohammedMojaly\Laralyze\Support\Chart')
@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Database">
    @if (($totals->count ?? 0) > 0)
        <div class="lz-figures">
            <x-laralyze::figure :value="Format::number($totals->count)" label="queries" />
            <x-laralyze::legend :items="['total time' => $totals->sum, 'avg' => $totals->avg, 'p95' => $totals->p95]" format="duration" />
        </div>

        <x-laralyze::lines :chart="new Chart($series, $this->range())" format="duration" />

        <div class="lz-split">
            <dl class="lz-list">
                @foreach (['read' => 'Reads', 'write' => 'Writes'] as $kind => $label)
                    <div>
                        <dt>{{ $label }}</dt>
                        <dd>{{ Format::number($kinds[$kind]->count ?? 0) }} <small>{{ Format::duration($kinds[$kind]->sum ?? null) }}</small></dd>
                    </div>
                @endforeach
            </dl>

            <dl class="lz-list">
                @foreach ($connections as $connection)
                    <div>
                        <dt class="lz-mono">{{ $connection->key }}</dt>
                        <dd>{{ Format::number($connection->count) }} <small>{{ Format::duration($connection->sum) }}</small></dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @else
        <x-laralyze::empty
            :title="'No queries in the '.$this->range()->label().'.'"
            hint="Query timings appear here a moment after each request or job finishes."
        />
    @endif
</x-laralyze::card>
