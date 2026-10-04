@php
    $traced = [
        'requests' => ['type' => 'request', 'name' => $name, 'title' => 'Requests'],
        'jobs' => ['type' => 'job', 'name' => $name, 'title' => 'Attempts', 'order' => 'recent'],
        'commands' => ['type' => 'command', 'name' => $name, 'title' => 'Runs', 'order' => 'recent'],
        'users' => ['type' => 'request', 'user' => $name, 'title' => 'Requests'],
        'exceptions' => ['exception' => $name, 'title' => 'Occurrences', 'order' => 'recent'],
    ][$page->key] ?? null;
    $tracing = (bool) config('laralyze.recorders.'.\MohammedMojaly\Laralyze\Recorders\Traces::class.'.enabled', false);
@endphp
@php
    $ask = ['exceptions' => 'exception', 'queries' => 'query', 'requests' => 'request'][$page->key] ?? null;
@endphp
<x-laralyze::page :title="$title" :mono="in_array($page->key, ['queries', 'commands'], true)" :ask="$ask ? [$ask, $name] : null">
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
            :title="$traced['title']"
            :order="$traced['order'] ?? 'slowest'"
        />
    @endif
</x-laralyze::page>
