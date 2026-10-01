{{-- A thin bar showing a share, 0 to 100. --}}
@props(['percent', 'tone' => null])
@php
    $percent = max(0, min(100, (float) $percent));
    $tone ??= $percent >= 90 ? 'bad' : ($percent >= 75 ? 'warn' : null);
@endphp
<span {{ $attributes->class(['lz-meter', "is-{$tone}" => $tone]) }} role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round($percent) }}">
    <span style="width: {{ round($percent, 1) }}%"></span>
</span>
