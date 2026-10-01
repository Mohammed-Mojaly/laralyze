@use('MohammedMojaly\Laralyze\Support\Chart')
@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Cache">
    @php($reads = $totals['hit'] + $totals['miss'])
    @if (array_sum($totals) > 0)
        <div class="lz-figures">
            <x-laralyze::figure :value="Format::percent($totals['hit'], $reads)" label="hit ratio" />
            <x-laralyze::legend :items="$totals" />
        </div>

        <x-laralyze::bars :chart="new Chart($series, $this->range())" />
    @else
        <x-laralyze::empty
            :title="'No cache activity in the '.$this->range()->label().'.'"
            hint="Hits, misses and writes appear here a moment after each request or job finishes."
        />
    @endif
</x-laralyze::card>
