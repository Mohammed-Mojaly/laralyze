@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Commands" :count="$commands->count()">
    <x-slot:actions>
        <x-laralyze::search placeholder="Search commands" />
    </x-slot:actions>

    @if ($commands->isEmpty())
        <x-laralyze::empty
            :title="$search === '' ? 'No commands ran in the '.$this->range()->label().'.' : 'No commands match “'.$search.'”.'"
            :hint="$search === '' ? 'Artisan commands appear here after they finish.' : null"
        />
    @else
        <x-laralyze::table class="lz-table-links">
            <x-slot:head>
                <x-laralyze::sort-header :card="$this" column="key" :num="false">Command</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="count">Runs</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="failed">Failed</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="avg">Avg</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="max">Slowest</x-laralyze::sort-header>
            </x-slot:head>

            @foreach ($commands as $command)
                <tr wire:key="{{ md5($command->key) }}">
                    <td class="lz-mono"><a class="lz-row-link" href="{{ $this->groupUrl('commands', $command->key) }}">{{ $command->key }}</a></td>
                    <td class="lz-num lz-strong">{{ Format::number($command->count) }}</td>
                    <td @class(['lz-num', 'lz-bad' => $command->failed > 0])>{{ Format::number($command->failed) }}</td>
                    <td class="lz-num">{{ Format::duration($command->avg) }}</td>
                    <td class="lz-num">{{ Format::duration($command->max) }}</td>
                </tr>
            @endforeach
        </x-laralyze::table>
    @endif
</x-laralyze::card>
