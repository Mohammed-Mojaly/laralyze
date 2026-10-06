{{-- A dashboard page. A group page passes its own title, and links back to its list. --}}
{{-- "ask" is what Ask AI is about here: [kind, key]; the whole app when not given. --}}
{{-- "tools" off gives the whole area to the page, without the heading, periods or Ask AI. --}}
@props(['title' => null, 'mono' => false, 'page' => null, 'ask' => null, 'tools' => true])
@inject('pages', 'MohammedMojaly\Laralyze\Dashboard\Pages')
@inject('health', 'MohammedMojaly\Laralyze\Dashboard\Health')
@php
    $current = $pages->find((string) ($page ?? request()->route('page') ?? \MohammedMojaly\Laralyze\Dashboard\Pages::HOME));
    $range = \MohammedMojaly\Laralyze\Dashboard\Range::fromQuery(request()->query('period'));
@endphp
<x-laralyze::layout :title="$title ? \Illuminate\Support\Str::limit($title, 60) : $current?->label">
    <div class="lz-app">
        <x-laralyze::sidebar :sections="$pages->sections()" :current="$current" :range="$range" :badges="$health->blocking() ? [] : app(\MohammedMojaly\Laralyze\Dashboard\Badges::class)->for($range)" :problems="count($health->problems())" />

        <main @class(['lz-main', 'lz-main-fill' => ! $tools]) id="content">
            @if ($tools)
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
                        @unless ($health->blocking())
                            <x-laralyze::ask :kind="$ask[0] ?? 'general'" :key="$ask[1] ?? ''" />
                        @endunless
                        <x-laralyze::periods :range="$range" />
                        <x-laralyze::theme-toggle />
                    </div>
                </header>
            @endif

            @foreach ($tools || $health->blocking() ? $health->problems() : [] as $problem)
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

    @if ($tools && ! $health->blocking() && \MohammedMojaly\Laralyze\Livewire\AssistantPanel::allowed())
        <livewire:laralyze.assistant />
    @endif
</x-laralyze::layout>
