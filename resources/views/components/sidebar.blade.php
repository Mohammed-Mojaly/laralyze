{{-- Pages by what you're after: what broke, what ran, what happened inside it, who uses it. System pages sit at the foot. --}}
@props(['sections', 'current', 'range', 'badges' => [], 'problems' => 0])
@php
    $footer = $sections['System'] ?? [];
    unset($sections['System']);
    $version = \MohammedMojaly\Laralyze\Laralyze::version();
    $storage = \MohammedMojaly\Laralyze\Dashboard\StorageInfo::describe(app());
@endphp
{{-- On a phone the sidebar is a bar at the top, and the menu opens from its button. --}}
<aside class="lz-sidebar" x-data="{ open: false }" :class="{ 'is-open': open }" x-on:keydown.escape.window="open = false">
    <a class="lz-brand" href="{{ route('laralyze.dashboard', $range === \MohammedMojaly\Laralyze\Dashboard\Range::Hour ? [] : ['period' => $range->value]) }}">
        {{-- An L made of trace spans. --}}
        <svg viewBox="0 0 32 32" aria-hidden="true">
            <rect x="5" y="4" width="5" height="24" rx="2.5" />
            <rect class="lz-brand-span" x="12.5" y="8.5" width="10" height="4.5" rx="2.25" />
            <rect class="lz-brand-span-2" x="16" y="15" width="7.5" height="4.5" rx="2.25" />
            <rect x="5" y="23.5" width="22" height="4.5" rx="2.25" />
        </svg>
        <span>Laralyze</span>
    </a>

    <span class="lz-sidebar-page">{{ $current?->label }}</span>

    <button type="button" class="lz-menu-button" x-on:click="open = ! open" :aria-expanded="open" aria-controls="lz-nav" aria-label="Menu">
        <x-laralyze::icon name="menu" x-show="! open" />
        <x-laralyze::icon name="close" x-show="open" x-cloak />
    </button>

    <nav class="lz-nav" id="lz-nav" aria-label="Laralyze">
        @foreach ($sections as $section => $sectionPages)
            <div class="lz-nav-section">
                @if ($section !== '')
                    <p class="lz-nav-label">{{ $section }}</p>
                @endif

                @foreach ($sectionPages as $page)
                    @include('laralyze::components.sidebar-link')
                @endforeach
            </div>
        @endforeach

        <div class="lz-nav-foot">
            @foreach ($footer as $page)
                @include('laralyze::components.sidebar-link')
            @endforeach

            <p @class(['lz-nav-status', 'lz-nav-status-warn' => $problems > 0])>
                <span class="lz-nav-dot" aria-hidden="true"></span>
                <span>{{ $problems > 0 ? 'Needs attention' : 'Recording' }}</span>
                @if ($version)
                    <span class="lz-nav-version">{{ $version }}</span>
                @endif
            </p>

            <p class="lz-nav-storage" title="{{ $storage['detail'] }}">
                <x-laralyze::icon name="queries" />
                <span class="lz-nav-storage-name">{{ $storage['label'] }}</span>
            </p>
        </div>
    </nav>
</aside>
