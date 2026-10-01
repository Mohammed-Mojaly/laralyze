@use('Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Commands">
    @if ($commands->isEmpty())
        <x-laralyze::empty
            :title="'No commands ran in the '.$this->range()->label().'.'"
            hint="Artisan commands appear here after they finish."
        />
    @else
        <x-laralyze::table>
            <x-slot:head>
                <th scope="col">Command</th>
                <th scope="col" class="lz-num">Runs</th>
                <th scope="col" class="lz-num">Failed</th>
                <th scope="col" class="lz-num">Avg</th>
                <th scope="col" class="lz-num">Slowest</th>
            </x-slot:head>

            @foreach ($commands as $command)
                <tr wire:key="{{ $command->key }}">
                    <td class="lz-mono">{{ $command->key }}</td>
                    <td class="lz-num lz-strong">{{ Format::number($command->count) }}</td>
                    <td @class(['lz-num', 'lz-bad' => $command->failed > 0])>{{ Format::number($command->failed) }}</td>
                    <td class="lz-num">{{ Format::duration($command->avg) }}</td>
                    <td class="lz-num">{{ Format::duration($command->max) }}</td>
                </tr>
            @endforeach
        </x-laralyze::table>
    @endif
</x-laralyze::card>
