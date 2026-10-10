<x-laralyze::page>
    <x-slot:filters>
        <x-laralyze::connections />
    </x-slot:filters>

    <livewire:laralyze.query-totals cols="full" />
    <livewire:laralyze.queries cols="full" />
    <livewire:laralyze.slow-queries cols="full" />
</x-laralyze::page>
