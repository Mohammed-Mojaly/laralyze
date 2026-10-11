@use('MohammedMojaly\Laralyze\Support\Chart')
@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Slowing down">
    @if (($connections->max ?? null) !== null)
        <div class="lz-figures">
            <x-laralyze::figure :value="round($connections->max).'%'" label="of connections in use at most" />
            <dl class="lz-legend">
                <div class="lz-legend-item lz-s-max"><dt><span class="lz-swatch"></span>most</dt><dd>{{ round($connections->max) }}%</dd></div>
                <div class="lz-legend-item lz-s-avg"><dt><span class="lz-swatch"></span>average</dt><dd>{{ round($connections->avg ?? 0) }}%</dd></div>
                <div class="lz-legend-item"><dt>long-running at once</dt><dd>{{ Format::number($longRunning ?? 0) }}</dd></div>
            </dl>
        </div>

        <x-laralyze::lines :chart="new Chart($series, $this->range())" />
    @endif

    <div @class(['lz-split' => ($connections->max ?? null) !== null])>
        @if (array_sum($errors) > 0)
            <dl class="lz-list">
                @foreach ($kinds as $kind => $label)
                    <div>
                        <dt>{{ $label }}</dt>
                        <dd @class(['lz-bad' => ($errors[$kind] ?? 0) > 0])>{{ Format::number($errors[$kind] ?? 0) }}</dd>
                    </div>
                @endforeach
            </dl>
        @else
            <p class="lz-note lz-list-note">No deadlocks, lock timeouts, refused or lost connections in the {{ $this->range()->label() }}.</p>
        @endif
    </div>
</x-laralyze::card>
