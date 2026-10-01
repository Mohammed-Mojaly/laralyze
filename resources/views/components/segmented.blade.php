{{-- Buttons that set a card property, e.g. the sort order. --}}
@props(['options', 'value', 'model', 'label' => 'Sort by'])
<div class="lz-segmented" role="group" aria-label="{{ $label }}">
    @foreach ($options as $option => $text)
        <button type="button" wire:click="$set('{{ $model }}', '{{ $option }}')" @class(['is-active' => $value === $option]) aria-pressed="{{ $value === $option ? 'true' : 'false' }}">{{ $text }}</button>
    @endforeach
</div>
