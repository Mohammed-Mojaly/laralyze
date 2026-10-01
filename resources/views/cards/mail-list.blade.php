@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Mail">
    @if ($mail->isEmpty())
        <x-laralyze::empty
            :title="'No mail sent in the '.$this->range()->label().'.'"
            hint="Each mailable gets a row once it has been sent."
        />
    @else
        <x-laralyze::table>
            <x-slot:head>
                <th scope="col">Mailable</th>
                <th scope="col" class="lz-num">Sent</th>
                <th scope="col" class="lz-num">Failed</th>
                <th scope="col" class="lz-num">Avg</th>
                <th scope="col" class="lz-num">Slowest</th>
            </x-slot:head>

            @foreach ($mail as $row)
                <tr wire:key="{{ $row->name }}">
                    <td><x-laralyze::class-name :name="$row->name" /></td>
                    <td class="lz-num lz-strong">{{ Format::number($row->sent) }}</td>
                    <td @class(['lz-num', 'lz-bad' => $row->failed > 0])>{{ Format::number($row->failed) }}</td>
                    <td class="lz-num">{{ Format::duration($row->avg) }}</td>
                    <td class="lz-num">{{ Format::duration($row->max) }}</td>
                </tr>
            @endforeach
        </x-laralyze::table>
    @endif
</x-laralyze::card>
