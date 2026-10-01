@props(['value', 'label'])
<p {{ $attributes->class('lz-figure') }}>
    <span class="lz-figure-value">{{ $value }}</span>
    <span class="lz-figure-label">{{ $label }}</span>
</p>
