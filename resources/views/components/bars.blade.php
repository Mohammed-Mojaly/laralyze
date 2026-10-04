{{-- Stacked bars, one per time slot. Series are stacked bottom to top in the order given. --}}
@props(['chart', 'format' => 'number'])
@php
    $max = $chart->max(stacked: true);
    $count = count($chart->slots);
@endphp
<figure {{ $attributes->class('lz-chart') }}>
    <div class="lz-chart-y" aria-hidden="true">
        <span>{{ \MohammedMojaly\Laralyze\Support\Format::as($format, $max) }}</span>
        <span>0</span>
    </div>

    <div class="lz-chart-plot lz-bars">
        @foreach ($chart->slots as $index => $time)
            @php($stack = $chart->stack($time))
            <div @class(['lz-bar', 'lz-tip-start' => $index < $count / 3, 'lz-tip-end' => $index >= $count * 2 / 3])>
                <div class="lz-bar-stack" style="height: {{ $max > 0 ? round($stack / $max * 100, 2) : 0 }}%">
                    @foreach ($chart->names() as $name)
                        @php($value = $chart->value($name, $time) ?? 0)
                        @if ($value > 0)
                            <span class="lz-seg lz-s-{{ $name }}" style="flex-grow: {{ $value }}"></span>
                        @endif
                    @endforeach
                </div>

                @if ($stack > 0)
                    <x-laralyze::chart-tip :chart="$chart" :time="$time" :format="$format" />
                @endif
            </div>
        @endforeach
    </div>

    @if ($count > 0)
        <figcaption class="lz-chart-x">
            <span>{{ $chart->label($chart->slots[0]) }}</span>
            <span>{{ $chart->label($chart->slots[$count - 1]) }}</span>
        </figcaption>
    @endif
</figure>
