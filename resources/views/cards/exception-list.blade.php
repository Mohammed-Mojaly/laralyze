@use('Laralyze\Support\Chart')
@use('Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Exceptions">
    @if ($exceptions->isEmpty())
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

        <x-laralyze::table>
            <x-slot:head>
                <th scope="col">Exception</th>
                <th scope="col" class="lz-num">Count</th>
                <th scope="col" class="lz-num">Unhandled</th>
                <th scope="col">Latest</th>
            </x-slot:head>

            @foreach ($exceptions as $exception)
                <tr wire:key="{{ $exception->key }}">
                    <td class="lz-wrap">
                        <x-laralyze::class-name :name="$exception->class" />
                        @if ($exception->message)
                            <span class="lz-sub">{{ $exception->message }}</span>
                        @endif
                        <span class="lz-sub lz-mono">{{ $exception->location }}</span>
                    </td>
                    <td class="lz-num lz-strong">{{ Format::number($exception->count) }}</td>
                    <td @class(['lz-num', 'lz-bad' => $exception->unhandled > 0])>{{ Format::number($exception->unhandled) }}</td>
                    <td><x-laralyze::ago :at="$exception->latest" /></td>
                </tr>
            @endforeach
        </x-laralyze::table>
    @endif
</x-laralyze::card>
