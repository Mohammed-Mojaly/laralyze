{{-- The database's sessions at work, longest first: what each runs, for how long, and who it waits for. --}}
@props(['sessions'])
@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::table>
    <x-slot:head>
        <th scope="col" class="lz-num">Session</th>
        <th scope="col">User</th>
        <th scope="col">Host</th>
        <th scope="col" class="lz-num">For</th>
        <th scope="col">State</th>
        <th scope="col" class="lz-num">Blocked by</th>
        <th scope="col" class="lz-num">Rows locked</th>
        <th scope="col">Query</th>
    </x-slot:head>

    @foreach ($sessions as $session)
        <tr @class(['lz-db-blocked' => ($session['blocked_by'] ?? null) !== null])>
            <td class="lz-num">{{ $session['id'] }}</td>
            <td class="lz-mono">{{ $session['user'] }}</td>
            <td class="lz-mono lz-muted">{{ $session['host'] ?? '' }}{{ ($session['database'] ?? '') !== '' ? ' · '.$session['database'] : '' }}</td>
            <td class="lz-num lz-nowrap">{{ Format::duration(($session['seconds'] ?? 0) * 1_000) }}</td>
            <td>{{ $session['state'] ?? '' }}</td>
            <td @class(['lz-num', 'lz-bad' => ($session['blocked_by'] ?? null) !== null])>{{ $session['blocked_by'] ?? '' }}</td>
            <td class="lz-num">{{ isset($session['rows_locked']) ? Format::number($session['rows_locked']) : '' }}</td>
            <td class="lz-wrap">@if (($session['query'] ?? '') !== '')<code class="lz-sql">{{ $session['query'] }}</code>@endif</td>
        </tr>
    @endforeach
</x-laralyze::table>
