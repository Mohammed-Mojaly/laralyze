{{-- Shows one database connection's queries, once the app uses more than one. --}}
@php
    $laralyze = app(\MohammedMojaly\Laralyze\Laralyze::class);
    $seconds = \MohammedMojaly\Laralyze\Dashboard\Range::fromQuery(request()->query('period'))->seconds();
    $names = (array) $laralyze->rescue(fn () => $laralyze->ignore(
        fn () => app(\MohammedMojaly\Laralyze\Contracts\Storage::class)->aggregate('query_connection', ['count'], $seconds, 'count')->map(fn ($row) => (string) $row->key)->all(),
    ), []);
    $current = is_string($chosen = request()->query('connection')) ? $chosen : '';

    if ($current !== '' && ! in_array($current, $names, true)) {
        $names[] = $current;
    }
@endphp
@if (count($names) > 1)
    <label class="lz-dropdown-button lz-select lz-connections">
        <span class="lz-dropdown-label">Connection</span>
        <select x-data x-on:change="window.location.href = $event.target.value">
            <option value="{{ request()->fullUrlWithQuery(['connection' => null]) }}">All</option>
            @foreach ($names as $name)
                <option value="{{ request()->fullUrlWithQuery(['connection' => $name]) }}" @selected($name === $current)>{{ $name }}</option>
            @endforeach
        </select>
        <span class="lz-dropdown-caret" aria-hidden="true">▾</span>
    </label>
@endif
