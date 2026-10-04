{{-- A chart in an answer, drawn from Laralyze's data, or a ranking of values the answer gave. --}}
@use('MohammedMojaly\Laralyze\Support\Format')
<figure class="lz-answer-chart">
    @if ($chart['title'] !== '')
        <figcaption class="lz-answer-chart-title" dir="auto">{{ $chart['title'] }}</figcaption>
    @endif

    @if ($chart['type'] === 'ranking')
        @php($top = max(array_map(fn (array $item) => abs($item['value']), $chart['items'])) ?: 1)
        <ol class="lz-ranking">
            @foreach ($chart['items'] as $item)
                <li>
                    <span class="lz-ranking-label" dir="auto" title="{{ $item['label'] }}">{{ $item['label'] }}</span>
                    <span class="lz-ranking-bar"><span style="width: {{ round(abs($item['value']) / $top * 100, 1) }}%"></span></span>
                    <span class="lz-ranking-value">{{ Format::as($chart['format'], $item['value']) }}</span>
                </li>
            @endforeach
        </ol>
    @else
        @if (count($chart['chart']->names()) > 1)
            <div class="lz-answer-legend">
                @foreach ($chart['chart']->names() as $name)
                    <span class="lz-legend-item lz-s-{{ $name }}"><span class="lz-swatch"></span>{{ $chart['chart']->title($name) }}</span>
                @endforeach
            </div>
        @endif

        @if ($chart['chart']->isEmpty())
            <p class="lz-muted">Nothing recorded for this in the {{ $chart['chart']->range->label() }}.</p>
        @elseif ($chart['type'] === 'bars')
            <x-laralyze::bars :chart="$chart['chart']" :format="$chart['format']" />
        @else
            <x-laralyze::lines :chart="$chart['chart']" :format="$chart['format']" />
        @endif
    @endif
</figure>
