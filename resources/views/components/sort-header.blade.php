{{-- A table header that sorts the list. Needs a card using ListsRows. --}}
@props(['card', 'column', 'num' => true])
@php
    $active = $card->sortColumn() === $column;
    $direction = $active ? $card->direction : null;
@endphp
<th scope="col" @class(['lz-num' => $num]) @if ($active) aria-sort="{{ $direction === 'asc' ? 'ascending' : 'descending' }}" @endif>
    <button type="button" @class(['lz-sort', 'is-active' => $active]) wire:click="sortBy('{{ $column }}')">
        {{ $slot }}
        <span class="lz-sort-arrow" aria-hidden="true">{{ $direction === 'asc' ? '▲' : ($direction === 'desc' ? '▼' : '↕') }}</span>
    </button>
</th>
