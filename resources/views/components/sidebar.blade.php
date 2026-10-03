@props(['sections', 'current', 'range', 'badges' => []])
<aside class="lz-sidebar">
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

    <nav class="lz-nav" aria-label="Laralyze">
        @foreach ($sections as $section => $sectionPages)
            <div class="lz-nav-section">
                @if ($section !== '')
                    <p class="lz-nav-label">{{ $section }}</p>
                @endif

                @foreach ($sectionPages as $page)
                    <a
                        href="{{ $page->url($range) }}"
                        @class(['lz-nav-link', 'is-active' => $current?->key === $page->key])
                        @if ($current?->key === $page->key) aria-current="page" @endif
                    >
                        <x-laralyze::icon :name="$page->icon" />
                        <span>{{ $page->label }}</span>
                        @if ($badges[$page->key] ?? 0)
                            <span @class(['lz-nav-count', 'lz-nav-count-warn' => $page->key !== 'exceptions']) title="{{ $page->key === 'exceptions' ? 'Open exceptions' : 'N+1 and duplicate queries' }} in the {{ $range->label() }}">{{ \MohammedMojaly\Laralyze\Support\Format::number($badges[$page->key]) }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endforeach
    </nav>
</aside>
