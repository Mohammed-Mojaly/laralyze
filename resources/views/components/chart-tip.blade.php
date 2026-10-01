@props(['chart', 'time', 'format' => 'number'])
<div class="lz-tip" role="tooltip">
    <p class="lz-tip-time">{{ $chart->label($time) }}</p>

    @foreach (array_reverse($chart->names()) as $name)
        @php($value = $chart->value($name, $time))
        <p class="lz-tip-row lz-s-{{ $name }}">
            <span class="lz-swatch"></span>
            <span>{{ $name }}</span>
            <b>{{ $format === 'duration' ? \MohammedMojaly\Laralyze\Support\Format::duration($value) : \MohammedMojaly\Laralyze\Support\Format::number($value ?? 0) }}</b>
        </p>
    @endforeach
</div>
