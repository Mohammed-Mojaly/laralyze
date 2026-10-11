@use('MohammedMojaly\Laralyze\Support\Format')
@php
    $badges = ['ok' => '', 'warning' => 'lz-badge-warn', 'problem' => 'lz-badge-bad'];
@endphp
<x-laralyze::card :card="$this" title="Later">
    @if ($size === null)
        <x-laralyze::empty
            title="Not measured yet."
            hint="Laralyze measures the size once an hour, starting with the scheduler's first run."
        />
    @else
        <div class="lz-figures">
            <x-laralyze::figure :value="Format::bytes($size['total'])" :label="Format::number($size['count']).' tables'" />
            <p class="lz-note lz-db-growth">
                @if ($perDay === null)
                    Growth shows after a day of measuring.
                @else
                    {{ $perDay >= 0 ? '+' : '−' }}{{ Format::bytes(abs($perDay)) }} a day over the last {{ $days }} {{ $days === 1 ? 'day' : 'days' }}
                @endif
            </p>
        </div>

        @if (collect($size['tables'])->whereNotNull('size')->isEmpty())
            <p class="lz-note lz-list-note">This database can't size its tables one by one, so only the total shows.</p>
        @else
            <div class="lz-split">
                <div>
                    <h3 class="lz-label">Largest tables</h3>
                    <dl class="lz-list">
                        @foreach (array_slice($size['tables'], 0, 8) as $table)
                            <div>
                                <dt class="lz-mono">{{ $table['name'] }} @if ($table['own'])<span class="lz-badge" title="Laralyze's own table">Laralyze</span>@endif</dt>
                                <dd>{{ $table['size'] === null ? '—' : Format::bytes($table['size']) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                <div>
                    <h3 class="lz-label">Fastest growing</h3>
                    @if ($growing === [])
                        <p class="lz-note lz-list-note">{{ $perDay === null ? 'Shows after a day of measuring.' : 'No table grew.' }}</p>
                    @else
                        <dl class="lz-list">
                            @foreach (array_slice($growing, 0, 8, true) as $table => $growth)
                                <div>
                                    <dt class="lz-mono">{{ $table }}</dt>
                                    <dd>+{{ Format::bytes($growth) }} <small>a day</small></dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </div>
            </div>
        @endif

        @if (($size['ids'] ?? null) !== null)
            <div class="lz-split">
                <div>
                    <h3 class="lz-label">Ids running out</h3>
                    @if ($size['ids'] === [])
                        <p class="lz-note lz-list-note">No auto-increment ids.</p>
                    @else
                        <ul class="lz-db-checks">
                            @foreach ($size['ids'] as $id)
                                <li>
                                    <div class="lz-db-check">
                                        <span @class(['lz-badge', $badges[$id['status']] ?? ''])>{{ round($id['percent']) }}%</span>
                                        <span class="lz-db-check-label lz-mono">{{ $id['table'] }}.{{ $id['column'] }}</span>
                                        <span class="lz-db-check-value">{{ $id['type'] }}</span>
                                    </div>
                                    @if ($id['status'] !== 'ok')
                                        <p class="lz-db-advice">{{ Format::number($id['used']) }} of {{ Format::number($id['max']) }} used. Change the column to a larger type, like bigint, before inserts start to fail.</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        @endif

        <p class="lz-note lz-list-note">Measured once an hour, last <x-laralyze::ago :at="$size['at']" /></p>
    @endif
</x-laralyze::card>
