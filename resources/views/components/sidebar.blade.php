@props(['sections', 'current', 'range'])
<aside class="lz-sidebar">
    <a class="lz-brand" href="{{ route('laralyze.dashboard', $range === \MohammedMojaly\Laralyze\Dashboard\Range::Hour ? [] : ['period' => $range->value]) }}">
        <svg viewBox="0 0 32 32" aria-hidden="true">
            <rect width="32" height="32" rx="7" />
            <path d="M6 16c3-5 6.5-7.5 10-7.5S23 11 26 16c-3 5-6.5 7.5-10 7.5S9 21 6 16Z" />
            <circle cx="16" cy="16" r="3.2" />
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
                    </a>
                @endforeach
            </div>
        @endforeach
    </nav>
</aside>
