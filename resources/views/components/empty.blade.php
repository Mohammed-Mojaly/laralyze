@props(['title', 'hint' => null])
<div {{ $attributes->class('lz-empty') }}>
    <p class="lz-empty-title">{{ $title }}</p>

    @if ($hint)
        <p class="lz-empty-hint">{{ $hint }}</p>
    @endif
</div>
