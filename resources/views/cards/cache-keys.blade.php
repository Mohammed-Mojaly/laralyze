@use('Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Cache keys">
    @if ($keys->isEmpty())
        <x-laralyze::empty
            :title="'No cache activity in the '.$this->range()->label().'.'"
            hint="Keys are grouped, so user:1 and user:2 share a row as user:*."
        />
    @else
        <x-laralyze::table>
            <x-slot:head>
                <th scope="col">Key</th>
                <th scope="col" class="lz-num">Hits</th>
                <th scope="col" class="lz-num">Misses</th>
                <th scope="col" class="lz-num">Hit ratio</th>
                <th scope="col" class="lz-num">Writes</th>
                <th scope="col" class="lz-num">Deletes</th>
                <th scope="col" class="lz-num">Failures</th>
            </x-slot:head>

            @foreach ($keys as $key)
                <tr wire:key="{{ md5($key->key) }}">
                    <td class="lz-mono">{{ $key->key }}</td>
                    <td class="lz-num lz-strong">{{ Format::number($key->hit) }}</td>
                    <td class="lz-num">{{ Format::number($key->miss) }}</td>
                    <td class="lz-num">{{ Format::percent($key->hit, $key->hit + $key->miss) }}</td>
                    <td class="lz-num">{{ Format::number($key->write) }}</td>
                    <td class="lz-num">{{ Format::number($key->delete) }}</td>
                    <td @class(['lz-num', 'lz-bad' => $key->failure > 0])>{{ Format::number($key->failure) }}</td>
                </tr>
            @endforeach
        </x-laralyze::table>
    @endif
</x-laralyze::card>
