@use('MohammedMojaly\Laralyze\Support\Brands')
@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Models" :count="$models->count()">
    @if ($models->isEmpty())
        <x-laralyze::empty :title="'No AI calls in the '.$this->range()->label().'.'" />
    @else
        <x-laralyze::table class="lz-table-links">
            <x-slot:head>
                <x-laralyze::sort-header :card="$this" column="name" :num="false">Model</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="count">Calls</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="tokens">Tokens</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="cost">Cost</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="failed">Failed</x-laralyze::sort-header>
                <th scope="col" class="lz-num" title="USD per million tokens, input / output">Per 1M</th>
            </x-slot:head>

            @foreach ($models as $model)
                <tr wire:key="{{ md5($model->key) }}">
                    <td>
                        <a class="lz-row-link" href="{{ $this->groupUrl('ai', $model->key) }}" title="{{ $model->provider }}">
                            <span class="lz-with-mark">{!! Brands::svg($model->provider, 'ai') !!}<span class="lz-mono">{{ $model->name }}</span></span>
                        </a>
                    </td>
                    <td class="lz-num lz-strong">{{ Format::number($model->count) }}</td>
                    <td class="lz-num">{{ Format::number($model->tokens) }}</td>
                    <td @class(['lz-num', 'lz-muted' => $model->cost === null])>{{ Format::money($model->cost) }}</td>
                    <td @class(['lz-num', 'lz-bad' => $model->failed > 0, 'lz-muted' => $model->failed == 0])>{{ Format::number($model->failed) }}</td>
                    @if ($model->price === null)
                        <td class="lz-num lz-muted" title="Add its price under prices in config/laralyze.php">price unknown</td>
                    @else
                        <td class="lz-num lz-muted">{{ Format::money($model->price[0]) }} / {{ $model->price[1] > 0 ? Format::money($model->price[1]) : '—' }}</td>
                    @endif
                </tr>
            @endforeach
        </x-laralyze::table>
    @endif
</x-laralyze::card>
