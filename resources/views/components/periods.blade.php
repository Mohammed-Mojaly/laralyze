@props(['range'])
<nav class="lz-periods" aria-label="Period">
    @foreach (\Laralyze\Dashboard\Range::cases() as $option)
        <a
            href="{{ request()->fullUrlWithQuery(['period' => $option === \Laralyze\Dashboard\Range::Hour ? null : $option->value]) }}"
            @class(['is-active' => $option === $range])
            @if ($option === $range) aria-current="true" @endif
            title="{{ ucfirst($option->label()) }}"
        >{{ $option->value }}</a>
    @endforeach
</nav>
