{{-- A dashboard page. A group page passes its own title, and links back to its list. --}}
@props(['title' => null, 'mono' => false, 'page' => null])
@inject('pages', 'MohammedMojaly\Laralyze\Dashboard\Pages')
@inject('health', 'MohammedMojaly\Laralyze\Dashboard\Health')
@php
    $current = $pages->find((string) ($page ?? request()->route('page') ?? \MohammedMojaly\Laralyze\Dashboard\Pages::HOME));
    $range = \MohammedMojaly\Laralyze\Dashboard\Range::fromQuery(request()->query('period'));
@endphp
<x-laralyze::layout :title="$title ? \Illuminate\Support\Str::limit($title, 60) : $current?->label">
    <div class="lz-app">
        <x-laralyze::sidebar :sections="$pages->sections()" :current="$current" :range="$range" />

        <main class="lz-main" id="content">
            <header class="lz-topbar">
                <div class="lz-heading">
                    @if ($title && $current)
                        <a class="lz-back" href="{{ $current->url($range) }}"><x-laralyze::icon name="back" />{{ $current->label }}</a>
                        <h1 @class(['lz-heading-mono' => $mono]) title="{{ $title }}">{{ $title }}</h1>
                    @else
                        <h1>{{ $current?->label ?? 'Laralyze' }}</h1>
                    @endif
                    <p>{{ ucfirst($range->label()) }}</p>
                </div>

                <div class="lz-topbar-tools">
                    <x-laralyze::periods :range="$range" />
                    <x-laralyze::theme-toggle />
                </div>
            </header>

            @foreach ($health->problems() as $problem)
                <div @class(['lz-alert', 'lz-alert-'.$problem['level']]) role="alert">
                    <p class="lz-alert-title">{{ $problem['title'] }}</p>
                    <p class="lz-alert-hint">{{ $problem['hint'] }}</p>
                </div>
            @endforeach

            @unless ($health->blocking())
                <div {{ $attributes->class('lz-grid') }}>
                    {{ $slot }}
                </div>
            @endunless
        </main>
    </div>
</x-laralyze::layout>
