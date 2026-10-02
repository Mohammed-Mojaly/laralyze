{{-- How one request, job or command ended: 200, 500, processed, failed, exit 1. --}}
@props(['execution'])
@php
    $status = (string) $execution->status;
    $label = $execution->type === 'command' ? 'exit '.$status : $status;
    $tone = match (true) {
        $execution->failed => 'lz-badge-bad',
        $execution->type === 'request' && (int) $status >= 400 => 'lz-badge-warn',
        default => '',
    };
@endphp
<span {{ $attributes->class(['lz-badge', $tone]) }}>{{ $label }}</span>
