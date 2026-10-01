@inject('pages', 'Laralyze\Dashboard\Pages')
@php
    $current = $pages->find((string) (request()->route('page') ?? \Laralyze\Dashboard\Pages::HOME));
    $range = \Laralyze\Dashboard\Range::fromQuery(request()->query('period'));
@endphp
<x-laralyze::layout :title="$current?->label">
    <div class="lz-app">
        <x-laralyze::sidebar :sections="$pages->sections()" :current="$current" :range="$range" />

        <main class="lz-main" id="content">
            <header class="lz-topbar">
                <div class="lz-heading">
                    <h1>{{ $current?->label ?? 'Laralyze' }}</h1>
                    <p>{{ ucfirst($range->label()) }}</p>
                </div>

                <div class="lz-topbar-tools">
                    <x-laralyze::periods :range="$range" />
                    <x-laralyze::theme-toggle />
                </div>
            </header>

            <div {{ $attributes->class('lz-grid') }}>
                {{ $slot }}
            </div>
        </main>
    </div>
</x-laralyze::layout>
