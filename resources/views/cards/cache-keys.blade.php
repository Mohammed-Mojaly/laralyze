@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Cache keys">
    @if ($keys->isEmpty())
        <x-laralyze::empty
            :title="'No cache activity in the '.$this->range()->label().'.'"
            hint="Keys are grouped, so user:1 and user:2 share a row as user:*."
        />
    @else
        <x-laralyze::table>
            <x-slot:head>
                <x-laralyze::sort-header :card="$this" column="key" :num="false">Key</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="hit">Hits</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="miss">Misses</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="ratio">Hit ratio</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="write">Writes</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="delete">Deletes</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="failure">Failures</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="total">Total</x-laralyze::sort-header>
            </x-slot:head>

            @foreach ($keys as $key)
                <tr wire:key="{{ md5($key->key) }}">
                    <td class="lz-mono">{{ $key->key }}</td>
                    <td class="lz-num">{{ Format::number($key->hit) }}</td>
                    <td class="lz-num">{{ Format::number($key->miss) }}</td>
                    <td class="lz-num">{{ $key->ratio === null ? '—' : Format::percent($key->hit, $key->hit + $key->miss) }}</td>
                    <td class="lz-num">{{ Format::number($key->write) }}</td>
                    <td class="lz-num">{{ Format::number($key->delete) }}</td>
                    <td @class(['lz-num', 'lz-bad' => $key->failure > 0])>{{ Format::number($key->failure) }}</td>
                    <td class="lz-num lz-strong">{{ Format::number($key->total) }}</td>
                </tr>
            @endforeach
        </x-laralyze::table>
    @endif
</x-laralyze::card>
