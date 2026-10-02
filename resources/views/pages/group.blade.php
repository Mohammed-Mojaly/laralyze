<x-laralyze::page :title="$title" :mono="in_array($page->key, ['queries', 'commands'], true)">
    @if ($page->key === 'exceptions')
        <livewire:laralyze.exception :name="$name" />
    @else
        <livewire:laralyze.group :page="$page->key" :name="$name" />
    @endif
</x-laralyze::page>
