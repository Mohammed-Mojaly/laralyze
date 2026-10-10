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
    {{-- The cards filter in place; the period links keep the choice. --}}
    <div
        class="lz-dropdown lz-connections"
        x-data="{
            open: false,
            current: @js($current),
            pick(name) {
                [this.current, this.open] = [name, false];
                Livewire.dispatch('laralyze-connection', { connection: name });
                document.querySelectorAll('.lz-periods a').forEach((link) => {
                    const url = new URL(link.href);
                    name === '' ? url.searchParams.delete('connection') : url.searchParams.set('connection', name);
                    link.href = url;
                });
            },
        }"
        x-on:click.outside="open = false"
        x-on:keydown.escape="open = false"
    >
        <button type="button" class="lz-dropdown-button" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="true">
            <span class="lz-dropdown-label">Connection</span>
            <span class="lz-mono" x-text="current === '' ? 'All' : current">{{ $current === '' ? 'All' : $current }}</span>
            <span class="lz-dropdown-caret" aria-hidden="true">▾</span>
        </button>
        <div class="lz-dropdown-menu" x-show="open" x-cloak>
            <button type="button" class="lz-option" x-bind:class="{ 'is-active': current === '' }" x-on:click="pick('')">All</button>
            @foreach ($names as $name)
                <button type="button" class="lz-option lz-mono" x-bind:class="{ 'is-active': current === @js($name) }" x-on:click="pick(@js($name))">{{ $name }}</button>
            @endforeach
        </div>
    </div>
@endif
