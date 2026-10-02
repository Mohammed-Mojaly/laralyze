{{-- The search box of a list card. Needs a card using ListsRows. --}}
@props(['placeholder' => 'Search'])
<label class="lz-search">
    <span class="lz-visually-hidden">{{ $placeholder }}</span>
    <x-laralyze::icon name="search" />
    <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ $placeholder }}" autocomplete="off" spellcheck="false">
</label>
