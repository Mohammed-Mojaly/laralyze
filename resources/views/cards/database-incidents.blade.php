@use('MohammedMojaly\Laralyze\Support\Format')
@php
    $badges = ['warning' => ['Warning', 'lz-badge-warn'], 'problem' => ['Problem', 'lz-badge-bad']];
    $time = fn (int $at) => \Illuminate\Support\Carbon::createFromTimestamp($at, (string) config('app.timezone', 'UTC'));
@endphp
<x-laralyze::card :card="$this" title="Incidents" :count="$incidents->count()">
    @if ($counts !== [] || ($deadlocks ?? 0) > 0)
        <p class="lz-note lz-db-tally">
            @foreach ($counts as $kind => $count)
                <span>{{ $kinds[$kind] }} <strong>{{ Format::number($count) }}</strong></span>
            @endforeach
            @if (($deadlocks ?? 0) > 0)
                <span>Deadlocks in the database <strong>{{ Format::number($deadlocks) }}</strong>, {{ Format::number($reached) }} reached the app</span>
            @endif
        </p>
    @endif

    @if (array_sum($counts) > $incidents->count())
        <p class="lz-note">Details are kept for the latest {{ \MohammedMojaly\Laralyze\Cards\DatabaseIncidents::LIMIT }} here, and for the first {{ \MohammedMojaly\Laralyze\Database\Incidents::PER_DAY }} each day; the others are counted above.</p>
    @endif

    @if ($incidents->isEmpty())
        <x-laralyze::empty
            :title="'No incidents in the '.$this->range()->label().'.'"
            hint="Each minute's look notes blocking, long-running queries, transactions left open, connections near the limit, deadlocks and restarts, with what was going on at the time."
        />
    @else
        <ul class="lz-logs">
            @foreach ($incidents as $incident)
                @php($isOpen = $expanded === $incident['id'])
                @php($lasted = (int) $incident['last_seen'] - (int) $incident['started_at'])
                <li wire:key="{{ md5($incident['id']) }}" @class(['lz-log', 'is-open' => $isOpen])>
                    <button type="button" class="lz-log-row lz-incident-row" wire:click="toggle(@js($incident['id']))" aria-expanded="{{ $isOpen ? 'true' : 'false' }}">
                        <span class="lz-log-at" title="{{ $time($incident['started_at'])->format('M j, H:i') }}">{{ $time($incident['started_at'])->format('H:i') }}</span>
                        <span><span @class(['lz-badge', $badges[$incident['status']][1] ?? ''])>{{ $badges[$incident['status']][0] ?? $incident['status'] }}</span></span>
                        <span class="lz-incident-kind">{{ $kinds[$incident['kind']] ?? $incident['kind'] }}</span>
                        <span class="lz-log-message">{{ $incident['summary'] }}</span>
                        <span class="lz-log-at">{{ $lasted > 0 ? 'for '.Format::duration($lasted * 1_000) : '' }}</span>
                    </button>

                    @if ($isOpen)
                        <div class="lz-log-detail">
                            <p class="lz-db-advice">{{ $incident['summary'] }}</p>

                            <dl class="lz-log-facts">
                                <div><dt>Started</dt><dd>{{ $time($incident['started_at'])->format('M j, Y H:i') }}</dd></div>
                                <div><dt>Last seen</dt><dd>{{ $time($incident['last_seen'])->format('M j, Y H:i') }}</dd></div>
                                @isset($incident['deadlock']['at'])
                                    <div><dt>In the database's time</dt><dd>{{ $incident['deadlock']['at'] }}</dd></div>
                                @endisset
                            </dl>

                            @if (isset($incident['deadlock']['transactions']))
                                <x-laralyze::table>
                                    <x-slot:head>
                                        <th scope="col" class="lz-num">#</th>
                                        <th scope="col" class="lz-num">Session</th>
                                        <th scope="col">Query</th>
                                        <th scope="col">Held</th>
                                        <th scope="col">Waited for</th>
                                    </x-slot:head>

                                    @foreach ($incident['deadlock']['transactions'] as $transaction)
                                        <tr>
                                            <td class="lz-num">{{ $transaction['number'] }}@if ($transaction['rolled_back']) <span class="lz-badge lz-badge-bad">rolled back</span>@endif</td>
                                            <td class="lz-num">{{ $transaction['session'] }}</td>
                                            <td class="lz-wrap"><code class="lz-sql">{{ $transaction['query'] }}</code></td>
                                            <td class="lz-mono">{{ $transaction['holds'] }}</td>
                                            <td class="lz-mono">{{ $transaction['waits'] }}</td>
                                        </tr>
                                    @endforeach
                                </x-laralyze::table>
                                <p class="lz-note">Two transactions each waited for a lock the other held, so the database rolled one back. Taking locks in the same order everywhere, or keeping transactions short, usually stops it.</p>
                            @elseif (($incident['sessions'] ?? []) !== [])
                                <h3 class="lz-log-heading">Sessions at its worst</h3>
                                <x-laralyze::db-sessions :sessions="$incident['sessions']" />
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</x-laralyze::card>
