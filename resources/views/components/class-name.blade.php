{{-- App\Jobs\SendInvoice shows as SendInvoice, with the namespace muted. --}}
@props(['name'])
@php
    $name = (string) $name;
    $short = class_basename($name);
    $namespace = $short === $name ? '' : substr($name, 0, -strlen($short));
@endphp
<span {{ $attributes->class('lz-class') }} title="{{ $name }}">@if ($namespace !== '')<span class="lz-namespace">{{ $namespace }}</span>@endif<span class="lz-basename">{{ $short }}</span></span>
