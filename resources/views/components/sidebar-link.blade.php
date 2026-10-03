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
