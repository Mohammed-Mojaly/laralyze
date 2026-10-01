@use('Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Notifications">
    @if ($notifications->isEmpty())
        <x-laralyze::empty
            :title="'No notifications sent in the '.$this->range()->label().'.'"
            hint="Each notification and channel gets a row once it has been sent."
        />
    @else
        <x-laralyze::table>
            <x-slot:head>
                <th scope="col">Notification</th>
                <th scope="col">Channel</th>
                <th scope="col" class="lz-num">Sent</th>
                <th scope="col" class="lz-num">Failed</th>
                <th scope="col" class="lz-num">Avg</th>
            </x-slot:head>

            @foreach ($notifications as $row)
                <tr wire:key="{{ md5($row->name.$row->channel) }}">
                    <td><x-laralyze::class-name :name="$row->name" /></td>
                    <td class="lz-mono">{{ $row->channel }}</td>
                    <td class="lz-num lz-strong">{{ Format::number($row->sent) }}</td>
                    <td @class(['lz-num', 'lz-bad' => $row->failed > 0])>{{ Format::number($row->failed) }}</td>
                    <td class="lz-num">{{ Format::duration($row->avg) }}</td>
                </tr>
            @endforeach
        </x-laralyze::table>
    @endif
</x-laralyze::card>
