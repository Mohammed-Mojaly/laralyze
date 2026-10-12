@php
    $traced = \MohammedMojaly\Laralyze\Dashboard\Groups::executions($page->key, $name, app(\MohammedMojaly\Laralyze\Contracts\Storage::class));
    $tracing = (bool) config('laralyze.recorders.'.\MohammedMojaly\Laralyze\Recorders\Traces::class.'.enabled', false);
@endphp
@php
    $ask = ['exceptions' => 'exception', 'queries' => 'query', 'requests' => 'request'][$page->key] ?? null;
@endphp
<x-laralyze::page :title="$title" :mono="in_array($page->key, ['queries', 'commands', 'scheduled'], true)" :ask="$ask ? [$ask, $name] : null">
    @if ($page->key === 'exceptions')
        <livewire:laralyze.exception :name="$name" />
    @else
        <livewire:laralyze.group :page="$page->key" :name="$name" />
    @endif

    @if ($traced && $tracing)
        <livewire:laralyze.executions
            :type="$traced['type'] ?? ''"
            :name="$traced['name'] ?? ''"
            :user="$traced['user'] ?? ''"
            :exception="$traced['exception'] ?? ''"
            :call="$traced['call'] ?? ''"
            :title="$traced['title']"
            :order="$traced['order'] ?? 'slowest'"
        />
    @endif
</x-laralyze::page>
