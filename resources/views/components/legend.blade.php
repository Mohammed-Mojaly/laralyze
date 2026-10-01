{{-- A row of labelled figures, each with the series colour and its share of the total. --}}
@props(['items', 'total' => null, 'format' => 'number'])
<dl {{ $attributes->class('lz-legend') }}>
    @foreach ($items as $name => $value)
        <div @class(["lz-legend-item lz-s-{$name}", 'is-zero' => ! $value])>
            <dt><span class="lz-swatch"></span>{{ $name }}</dt>
            <dd>
                {{ $format === 'duration' ? \MohammedMojaly\Laralyze\Support\Format::duration($value) : \MohammedMojaly\Laralyze\Support\Format::number($value) }}
                @if ($total)
                    <small>{{ \MohammedMojaly\Laralyze\Support\Format::percent($value, $total) }}</small>
                @endif
            </dd>
        </div>
    @endforeach
</dl>
