@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Users">
    @if ($users->isEmpty())
        <x-laralyze::empty :title="'No AI calls in the '.$this->range()->label().'.'" />
    @else
        <x-laralyze::table class="lz-table-links">
            <x-slot:head>
                <th scope="col">User</th>
                <th scope="col" class="lz-num">Calls</th>
                <th scope="col" class="lz-num">Tokens</th>
                <th scope="col" class="lz-num">Cost</th>
            </x-slot:head>

            @foreach ($users as $user)
                <tr>
                    <td>
                        @if ($user->id === null)
                            <span class="lz-muted">{{ $user->name }}</span>
                        @elseif ($linked)
                            <a class="lz-row-link" href="{{ $this->groupUrl('users', $user->id) }}">{{ $user->name }}</a>
                        @else
                            {{ $user->name }}
                        @endif
                    </td>
                    <td class="lz-num lz-strong">{{ Format::number($user->count) }}</td>
                    <td class="lz-num">{{ Format::number($user->tokens) }}</td>
                    <td class="lz-num">{{ Format::money($user->cost) }}</td>
                </tr>
            @endforeach
        </x-laralyze::table>
    @endif
</x-laralyze::card>
