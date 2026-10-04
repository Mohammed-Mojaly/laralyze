{{-- Line chart, one line per series. Gaps in the data break the line. --}}
@props(['chart', 'format' => 'number'])
@php
    $max = $chart->max();
    $count = count($chart->slots);
@endphp
<figure {{ $attributes->class('lz-chart') }}>
    <div class="lz-chart-y" aria-hidden="true">
        <span>{{ \MohammedMojaly\Laralyze\Support\Format::as($format, $max) }}</span>
        <span>0</span>
    </div>

    <div class="lz-chart-plot lz-lines">
        <svg viewBox="0 -4 600 108" preserveAspectRatio="none" aria-hidden="true">
            <line class="lz-gridline" x1="0" y1="50" x2="600" y2="50" />
            @foreach ($chart->names() as $name)
                @foreach ($chart->lines($name, $max) as $points)
                    <polyline class="lz-line lz-s-{{ $name }}" points="{{ $points }}" />
                @endforeach
            @endforeach
        </svg>

        <div class="lz-hover">
            @foreach ($chart->slots as $index => $time)
                <div @class(['lz-hover-slot', 'lz-tip-start' => $index < $count / 3, 'lz-tip-end' => $index >= $count * 2 / 3])>
                    @if (collect($chart->names())->contains(fn ($name) => $chart->value($name, $time) !== null))
                        <x-laralyze::chart-tip :chart="$chart" :time="$time" :format="$format" />
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    @if ($count > 0)
        <figcaption class="lz-chart-x">
            <span>{{ $chart->label($chart->slots[0]) }}</span>
            <span>{{ $chart->label($chart->slots[$count - 1]) }}</span>
        </figcaption>
    @endif
</figure>
