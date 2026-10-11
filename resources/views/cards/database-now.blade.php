@use('MohammedMojaly\Laralyze\Support\Format')
@php
    $badges = ['ok' => ['OK', ''], 'warning' => ['Warning', 'lz-badge-warn'], 'problem' => ['Problem', 'lz-badge-bad']];
    $columns = ['id' => 'Session', 'waiting' => 'Waiting', 'blocking' => 'Blocked by', 'user' => 'User', 'seconds' => 'For', 'rows_locked' => 'Rows locked', 'query' => 'Query'];
@endphp
<x-laralyze::card :card="$this" title="Right now">
    @if ($snapshot === null)
        <x-laralyze::empty
            title="Laralyze hasn't looked at this database yet."
            hint="It looks every minute from your scheduler, so make sure your cron entry calls schedule:run."
        />
    @else
        <p class="lz-note lz-db-head">
            <span class="lz-mono">{{ $snapshot['engine'] }}</span>
            @if ($startedAt)
                <span>· started <x-laralyze::ago :at="$startedAt" /></span>
            @endif
            <span @class(['lz-warn' => $stale])>· {{ $stale ? 'last looked' : 'looked' }} <x-laralyze::ago :at="$snapshot['at']" />{{ $stale ? '. Is the scheduler running?' : '' }}</span>
        </p>

        @if ($checks === [] && $needs === [])
            <p class="lz-note">Nothing to look at live for this database. Its errors and size are below.</p>
        @endif

        <ul class="lz-db-checks">
            @foreach ($checks as $check)
                <li wire:key="check-{{ $check['key'] }}">
                    <div class="lz-db-check">
                        <span @class(['lz-badge', $badges[$check['status']][1] ?? ''])>{{ $badges[$check['status']][0] ?? $check['status'] }}</span>
                        <span class="lz-db-check-label">{{ $check['label'] }}</span>
                        <span class="lz-db-check-value">{{ $check['value'] }}</span>
                    </div>

                    @if ($check['percent'] !== null)
                        <x-laralyze::meter :percent="$check['percent']" />
                    @endif

                    @if ($check['advice'] !== '')
                        <p class="lz-db-advice">{{ $check['advice'] }}</p>
                    @endif

                    @if ($check['rows'] !== [])
                        <x-laralyze::table>
                            <x-slot:head>
                                @foreach (array_keys($check['rows'][0]) as $column)
                                    <th scope="col" @class(['lz-num' => ! in_array($column, ['user', 'query'], true)])>{{ $columns[$column] ?? $column }}</th>
                                @endforeach
                            </x-slot:head>

                            @foreach ($check['rows'] as $row)
                                <tr>
                                    @foreach ($row as $column => $value)
                                        @if ($column === 'query')
                                            <td class="lz-wrap"><code class="lz-sql">{{ $value }}</code></td>
                                        @elseif ($column === 'user')
                                            <td class="lz-mono">{{ $value }}</td>
                                        @else
                                            <td class="lz-num">{{ $column === 'seconds' ? Format::duration($value * 1_000) : Format::number($value) }}</td>
                                        @endif
                                    @endforeach
                                </tr>
                            @endforeach
                        </x-laralyze::table>
                    @endif
                </li>
            @endforeach
        </ul>

        @if ($needs !== [])
            <p class="lz-note lz-db-needs">
                @foreach ($needs as $privilege => $count)
                    {{ $count }} {{ $count === 1 ? 'check needs' : 'checks need' }} the {{ $privilege }} privilege.
                @endforeach
                <a href="https://github.com/Mohammed-Mojaly/laralyze/blob/main/docs/database.md#privileges" target="_blank" rel="noopener">What each privilege shows</a>
            </p>
        @endif
    @endif
</x-laralyze::card>
