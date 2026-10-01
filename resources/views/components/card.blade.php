@props(['card' => null, 'title' => null, 'cols' => null, 'rows' => null])
@php
    $cols = $cols ?? $card?->cols ?? 'full';
    $rows = $rows ?? $card?->rows ?? 1;
    $poll = $card?->poll ?? 0;
@endphp
<section
    {{ $attributes->class(['lz-card', "lz-span-{$cols}", "lz-rows-{$rows}", $card?->class]) }}
    @if ($poll > 0) wire:poll.visible.{{ $poll }}s @endif
>
    @if ($title || isset($actions))
        <header class="lz-card-head">
            <h2>{{ $title }}</h2>

            <div class="lz-card-actions">
                {{ $actions ?? '' }}

                @if ($card)
                    <span class="lz-took" title="Time spent reading this card's data">{{ \MohammedMojaly\Laralyze\Support\Format::duration($card->queryTime()) }}</span>
                @endif
            </div>
        </header>
    @endif

    <div class="lz-card-body">
        {{ $slot }}
    </div>
</section>
