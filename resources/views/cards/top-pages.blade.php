@use('Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Top pages">
    @if ($pages->isEmpty())
        <x-laralyze::empty
            :title="'No visits in the '.$this->range()->label().'.'"
            hint="The most visited pages appear here."
        />
    @else
        @php($most = (float) $pages->max('count'))
        <x-laralyze::table>
            <x-slot:head>
                <th scope="col">Page</th>
                <th scope="col" class="lz-num">Views</th>
            </x-slot:head>

            @foreach ($pages as $page)
                <tr wire:key="{{ md5($page->key) }}">
                    <td class="lz-bar-cell">
                        <span class="lz-path" title="{{ $page->key }}">{{ $page->key }}</span>
                        <x-laralyze::meter :percent="$most ? $page->count / $most * 100 : 0" tone="data" />
                    </td>
                    <td class="lz-num lz-strong">{{ Format::number($page->count) }}</td>
                </tr>
            @endforeach
        </x-laralyze::table>
    @endif
</x-laralyze::card>
