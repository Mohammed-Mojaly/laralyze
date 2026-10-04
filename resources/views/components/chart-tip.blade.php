@props(['chart', 'time', 'format' => 'number'])
<div class="lz-tip" role="tooltip">
    <p class="lz-tip-time">{{ $chart->label($time) }}</p>

    @foreach (array_reverse($chart->names()) as $name)
        @php($value = $chart->value($name, $time))
        <p class="lz-tip-row lz-s-{{ $name }}">
            <span class="lz-swatch"></span>
            <span>{{ $chart->title($name) }}</span>
            <b>{{ \MohammedMojaly\Laralyze\Support\Format::as($format, $value) }}</b>
        </p>
    @endforeach
</div>
