@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Users" :count="$users->count()">
    <x-slot:actions>
        <x-laralyze::search placeholder="Search users" />
    </x-slot:actions>

    @if ($users->isEmpty())
        <x-laralyze::empty
            :title="$search === '' ? 'No signed-in users in the '.$this->range()->label().'.' : 'No users match “'.$search.'”.'"
            :hint="$search === '' ? 'Signed-in users appear here once they make requests or queue jobs.' : null"
        />
    @else
        <x-laralyze::table class="lz-table-links">
            <x-slot:head>
                <x-laralyze::sort-header :card="$this" column="name" :num="false">User</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="ok">1/2/3xx</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="4xx">4xx</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="5xx">5xx</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="requests">Requests</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="slow">Slow</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="jobs">Jobs queued</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="exceptions">Exceptions</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="seen">Last seen</x-laralyze::sort-header>
            </x-slot:head>

            @foreach ($users as $user)
                <tr wire:key="{{ md5($user->id) }}">
                    <td>
                        <a class="lz-row-link" href="{{ $this->groupUrl('users', $user->id) }}">
                            <span class="lz-strong">{{ $user->name }}</span>
                            @if ($user->extra !== '')
                                <span class="lz-sub">{{ $user->extra }}</span>
                            @endif
                        </a>
                    </td>
                    <td class="lz-num">{{ Format::number($user->ok) }}</td>
                    <td @class(['lz-num', 'lz-warn' => $user->{'4xx'} > 0])>{{ Format::number($user->{'4xx'}) }}</td>
                    <td @class(['lz-num', 'lz-bad' => $user->{'5xx'} > 0])>{{ Format::number($user->{'5xx'}) }}</td>
                    <td class="lz-num lz-strong">{{ Format::number($user->requests) }}</td>
                    <td @class(['lz-num', 'lz-warn' => $user->slow > 0])>{{ Format::number($user->slow) }}</td>
                    <td class="lz-num">{{ Format::number($user->jobs) }}</td>
                    <td @class(['lz-num', 'lz-bad' => $user->exceptions > 0])>{{ Format::number($user->exceptions) }}</td>
                    <td class="lz-num"><x-laralyze::ago :at="$user->seen" /></td>
                </tr>
            @endforeach
        </x-laralyze::table>
    @endif
</x-laralyze::card>
