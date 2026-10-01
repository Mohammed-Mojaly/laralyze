@use('Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Users">
    @if ($users->isEmpty())
        <x-laralyze::empty
            :title="'No signed-in users in the '.$this->range()->label().'.'"
            hint="Signed-in users appear here once they make requests or queue jobs."
        />
    @else
        <x-laralyze::table>
            <x-slot:head>
                <th scope="col">User</th>
                <th scope="col" class="lz-num">Requests</th>
                <th scope="col" class="lz-num">Slow requests</th>
                <th scope="col" class="lz-num">Jobs queued</th>
            </x-slot:head>

            @foreach ($users as $user)
                <tr wire:key="{{ $user->id }}">
                    <td>
                        <span class="lz-strong">{{ $user->name }}</span>
                        @if ($user->extra !== '')
                            <span class="lz-sub">{{ $user->extra }}</span>
                        @endif
                    </td>
                    <td class="lz-num lz-strong">{{ Format::number($user->requests) }}</td>
                    <td @class(['lz-num', 'lz-warn' => $user->slow > 0])>{{ Format::number($user->slow) }}</td>
                    <td class="lz-num">{{ Format::number($user->jobs) }}</td>
                </tr>
            @endforeach
        </x-laralyze::table>
    @endif
</x-laralyze::card>
