@use('Laralyze\Support\Chart')
@use('Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Logs">
    @if ($total > 0)
        <div class="lz-figures">
            <x-laralyze::figure :value="Format::number($total)" label="messages" />
            <x-laralyze::legend :items="$totals" :total="$total" />
        </div>

        <x-laralyze::bars :chart="new Chart($series, $this->range())" />
    @else
        <x-laralyze::empty
            :title="'Nothing logged in the '.$this->range()->label().'.'"
            hint="Messages written with Log:: or logger() are counted here by level."
        />
    @endif
</x-laralyze::card>
