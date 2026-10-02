<x-laralyze::page :title="$title" :mono="in_array($page->key, ['queries', 'commands'], true)">
    <livewire:laralyze.group :page="$page->key" :name="$name" />
</x-laralyze::page>
