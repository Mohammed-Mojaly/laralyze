@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Bots">
    @if ($bots->isEmpty())
        <x-laralyze::empty
            :title="'No bots in the '.$this->range()->label().'.'"
            hint="Crawlers, link previews and scripts are counted here instead of as visitors."
        />
    @else
        <x-laralyze::table>
            <x-slot:head>
                <th scope="col">Bot</th>
                <th scope="col" class="lz-num">Requests</th>
            </x-slot:head>

            @foreach ($bots as $bot)
                <tr wire:key="{{ $bot->key }}">
                    <td><span class="lz-with-mark">{!! \MohammedMojaly\Laralyze\Support\Brands::svg((string) $bot->key, 'bot') !!}{{ $bot->key }}</span></td>
                    <td class="lz-num lz-strong">{{ Format::number($bot->count) }}</td>
                </tr>
            @endforeach
        </x-laralyze::table>
    @endif
</x-laralyze::card>
