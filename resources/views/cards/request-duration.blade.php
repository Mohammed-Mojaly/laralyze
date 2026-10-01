@use('MohammedMojaly\Laralyze\Support\Chart')
@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Duration">
    @if (($totals->count ?? 0) > 0)
        <div class="lz-figures">
            <p class="lz-figure">
                <span class="lz-figure-value">{{ Format::duration($totals->avg) }}</span>
                <span class="lz-figure-label">average</span>
            </p>

            <dl class="lz-legend">
                <div class="lz-legend-item lz-s-avg">
                    <dt><span class="lz-swatch"></span>avg</dt>
                    <dd>{{ Format::duration($totals->avg) }}</dd>
                </div>
                <div class="lz-legend-item lz-s-p95">
                    <dt><span class="lz-swatch"></span>p95</dt>
                    <dd>{{ Format::duration($totals->p95) }}</dd>
                </div>
                <div class="lz-legend-item">
                    <dt>p99</dt>
                    <dd>{{ Format::duration($totals->p99) }}</dd>
                </div>
                <div class="lz-legend-item">
                    <dt>slowest</dt>
                    <dd>{{ Format::duration($totals->max) }}</dd>
                </div>
            </dl>
        </div>

        <x-laralyze::lines :chart="new Chart($series, $this->range())" format="duration" />
    @else
        <x-laralyze::empty
            :title="'No requests in the '.$this->range()->label().'.'"
            hint="Timings appear here a moment after requests finish."
        />
    @endif
</x-laralyze::card>
