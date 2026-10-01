@inject('pages', 'Laralyze\Dashboard\Pages')
@php
    $has = fn (string $recorder) => $pages->isRecorderEnabled($recorder);
@endphp
<x-laralyze::page>
    @if ($has(\Laralyze\Recorders\Requests::class))
        <livewire:laralyze.request-totals cols="6" />
        <livewire:laralyze.request-duration cols="6" />
    @endif

    @if ($has(\Laralyze\Recorders\Exceptions::class))
        <livewire:laralyze.exceptions cols="full" limit="5" />
    @endif

    @if ($has(\Laralyze\Recorders\Jobs::class))
        <livewire:laralyze.queues cols="full" />
    @endif

    @if ($has(\Laralyze\Recorders\Requests::class))
        <livewire:laralyze.slow-requests cols="full" limit="5" />
    @endif

    @unless ($has(\Laralyze\Recorders\Requests::class) || $has(\Laralyze\Recorders\Exceptions::class) || $has(\Laralyze\Recorders\Jobs::class))
        <x-laralyze::empty
            class="lz-span-full"
            title="Nothing is being recorded yet."
            hint="Turn on a recorder in config/laralyze.php and its numbers will show up here."
        />
    @endunless
</x-laralyze::page>
