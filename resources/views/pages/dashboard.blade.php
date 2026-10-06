@inject('pages', 'MohammedMojaly\Laralyze\Dashboard\Pages')
@php
    $has = fn (string $recorder) => $pages->isRecorderEnabled($recorder);
    $requests = $has(\MohammedMojaly\Laralyze\Recorders\Requests::class);
    $jobs = $has(\MohammedMojaly\Laralyze\Recorders\Jobs::class);
@endphp
<x-laralyze::page>
    {{-- Hidden until something needs attention, so it loads with the page, not when scrolled into view. --}}
    <livewire:laralyze.attention cols="full" lazy="on-load" />

    @if ($requests)
        <livewire:laralyze.request-totals cols="6" />
        <livewire:laralyze.request-duration cols="6" />
    @endif

    @if ($has(\MohammedMojaly\Laralyze\Recorders\Exceptions::class))
        <livewire:laralyze.exceptions cols="full" compact />
    @endif

    @if ($jobs)
        <livewire:laralyze.queues cols="full" compact />
    @endif

    @if ($requests)
        <livewire:laralyze.slow-requests :cols="$jobs ? 6 : 'full'" limit="5" />
    @endif

    @if ($jobs)
        <livewire:laralyze.slow-jobs :cols="$requests ? 6 : 'full'" limit="5" />
    @endif

    @if ($pages->find('ai'))
        <livewire:laralyze.ai-totals cols="full" summary />
    @endif

    @unless ($requests || $jobs || $has(\MohammedMojaly\Laralyze\Recorders\Exceptions::class))
        <x-laralyze::empty
            class="lz-span-full"
            title="Nothing is being recorded yet."
            hint="Turn on a recorder in config/laralyze.php and its numbers will show up here."
        />
    @endunless
</x-laralyze::page>
