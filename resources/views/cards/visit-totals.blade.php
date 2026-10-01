@use('Laralyze\Support\Chart')
@use('Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Visits">
    <div class="lz-figures">
        <p class="lz-figure">
            <span class="lz-live" aria-hidden="true"></span>
            <span class="lz-figure-value">{{ Format::number($live) }}</span>
            <span class="lz-figure-label">{{ $live === 1 ? 'visitor' : 'visitors' }} in the last 5 minutes</span>
        </p>

        <x-laralyze::legend :items="['page views' => $visits, 'unique visitors' => $visitors, 'bots' => $bots]" />
    </div>

    @if ($visits > 0)
        <x-laralyze::bars :chart="new Chart($series, $this->range())" />
    @else
        <x-laralyze::empty
            :title="'No visits in the '.$this->range()->label().'.'"
            hint="Page views appear here a moment after each visit. Only GET requests for pages count."
        />
    @endif
</x-laralyze::card>
