@php
    $laralyze = app(\MohammedMojaly\Laralyze\Laralyze::class);
    $looked = (array) $laralyze->rescue(fn () => app(\MohammedMojaly\Laralyze\Contracts\Storage::class)->values('database')->map(fn ($row) => (string) $row->key)->all(), []);
    $watched = array_values(array_unique([...\MohammedMojaly\Laralyze\Recorders\Database::watched(), ...$looked]));
@endphp
<x-laralyze::page :ask="['database', '']">
    <x-slot:filters>
        <x-laralyze::connections :names="$watched" :all="false" />
    </x-slot:filters>

    <livewire:laralyze.database-now cols="full" />
    <livewire:laralyze.database-incidents cols="full" />
    <livewire:laralyze.database-trends cols="full" />
    <livewire:laralyze.database-size cols="full" />
</x-laralyze::page>
