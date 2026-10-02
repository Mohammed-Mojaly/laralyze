@use('MohammedMojaly\Laralyze\Support\Chart')
@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Exceptions" :count="$exceptions->count()">
    <x-slot:actions>
        <x-laralyze::search placeholder="Search exceptions" />
        <div class="lz-segmented" role="group" aria-label="Show">
            @foreach (['all' => 'View all', 'handled' => 'Handled', 'unhandled' => 'Unhandled'] as $option => $text)
                <button type="button" wire:click="$set('show', '{{ $option }}')" @class(['is-active' => $show === $option]) aria-pressed="{{ $show === $option ? 'true' : 'false' }}">
                    {{ $text }}@if ($option === 'unhandled' && $unhandledCount > 0) <span class="lz-badge lz-badge-bad">{{ $unhandledCount }}</span>@endif
                </button>
            @endforeach
        </div>
    </x-slot:actions>

    @if (array_sum($totals) == 0)
        <x-laralyze::empty
            :title="'No exceptions in the '.$this->range()->label().'.'"
            hint="Reported exceptions appear here, grouped by class and the line that threw them."
        />
    @else
        <div class="lz-figures">
            <x-laralyze::figure :value="Format::number(array_sum($totals))" label="exceptions" />
            <x-laralyze::legend :items="$totals" :total="array_sum($totals)" />
        </div>

        <x-laralyze::bars :chart="new Chart($series, $this->range())" />

        @if ($exceptions->isEmpty())
            <x-laralyze::empty :title="$search === '' ? 'No '.$show.' exceptions.' : 'No exceptions match “'.$search.'”.'" />
        @else
            <x-laralyze::table class="lz-table-links">
                <x-slot:head>
                    <x-laralyze::sort-header :card="$this" column="latest" :num="false">Last seen</x-laralyze::sort-header>
                    <x-laralyze::sort-header :card="$this" column="class" :num="false">Exception</x-laralyze::sort-header>
                    <x-laralyze::sort-header :card="$this" column="count">Count</x-laralyze::sort-header>
                    <x-laralyze::sort-header :card="$this" column="users">Users</x-laralyze::sort-header>
                </x-slot:head>

                @foreach ($exceptions as $exception)
                    <tr wire:key="{{ md5((string) $exception->key) }}">
                        <td class="lz-nowrap"><x-laralyze::ago :at="$exception->latest" /></td>
                        <td class="lz-wrap">
                            <a class="lz-row-link" href="{{ $this->groupUrl('exceptions', (string) $exception->key) }}">
                                @if ($exception->unhandled > 0)
                                    <span class="lz-badge lz-badge-bad">Unhandled</span>
                                @else
                                    <span class="lz-badge">Handled</span>
                                @endif
                                <x-laralyze::class-name :name="$exception->class" />
                            </a>
                            @if ($exception->message)
                                <span class="lz-sub">{{ $exception->message }}</span>
                            @endif
                            <span class="lz-sub lz-mono">{{ $exception->location }}</span>
                        </td>
                        <td class="lz-num lz-strong">{{ Format::number($exception->count) }}</td>
                        <td class="lz-num">{{ Format::number($exception->users) }}</td>
                    </tr>
                @endforeach
            </x-laralyze::table>
        @endif
    @endif
</x-laralyze::card>
