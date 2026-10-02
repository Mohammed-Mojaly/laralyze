@use('MohammedMojaly\Laralyze\Support\Chart')
@use('MohammedMojaly\Laralyze\Support\Format')
@use('MohammedMojaly\Laralyze\Support\Sql')
<div class="lz-span-full lz-grid" @if ($this->poll > 0) wire:poll.visible.{{ $this->poll }}s @endif>
    <x-laralyze::card :title="$page === 'users' ? 'Requests' : 'Calls'" cols="6">
        @if ($calls > 0)
            <div class="lz-figures">
                <x-laralyze::figure :value="Format::number($calls)" :label="$page === 'users' ? ($calls == 1 ? 'request' : 'requests') : ($calls == 1 ? 'call' : 'calls')" />
                @if (count($counts) > 1)
                    <x-laralyze::legend :items="$counts" :total="$calls" />
                @endif
            </div>

            <x-laralyze::bars :chart="new Chart($series, $this->range())" />
        @else
            <x-laralyze::empty :title="'Not called in the '.$this->range()->label().'.'" hint="Pick a longer period to see older calls." />
        @endif
    </x-laralyze::card>

    <x-laralyze::card title="Duration" cols="6">
        @if (($totals->count ?? 0) > 0)
            <div class="lz-figures">
                <x-laralyze::figure :value="Format::duration($totals->avg)" label="average" />
                <dl class="lz-legend">
                    @foreach (array_keys($durations) as $line)
                        <div class="lz-legend-item lz-s-{{ $line }}">
                            <dt><span class="lz-swatch"></span>{{ $line === 'max' ? 'slowest' : $line }}</dt>
                            <dd>{{ Format::duration($totals->{$line} ?? null) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <x-laralyze::lines :chart="new Chart($durations, $this->range())" format="duration" />
        @else
            <x-laralyze::empty :title="'No timings in the '.$this->range()->label().'.'" />
        @endif
    </x-laralyze::card>

    <x-laralyze::card :card="$this" title="Info" :cols="$page === 'queries' ? 5 : 'full'">
        <dl class="lz-info">
            @foreach ($details as $label => [$value, $class])
                <div><dt>{{ $label }}</dt><dd @class([$class])>{{ $value }}</dd></div>
            @endforeach
            <div><dt>{{ $page === 'users' ? 'Requests' : 'Calls' }}</dt><dd>{{ Format::number($calls) }}</dd></div>
            @isset($counts['failed'])
                <div><dt>Failed</dt><dd @class(['lz-bad' => $counts['failed'] > 0])>{{ Format::number($counts['failed']) }}</dd></div>
            @endisset
            @isset($counts['5xx'])
                <div><dt>Errors (4xx / 5xx)</dt><dd><span @class(['lz-warn' => $counts['4xx'] > 0])>{{ Format::number($counts['4xx']) }}</span> / <span @class(['lz-bad' => $counts['5xx'] > 0])>{{ Format::number($counts['5xx']) }}</span></dd></div>
            @endisset
            <div><dt>Total time</dt><dd>{{ Format::duration($totals->sum ?? null) }}</dd></div>
            <div><dt>Average</dt><dd>{{ Format::duration($totals->avg ?? null) }}</dd></div>
            @if ($percentiles)
                <div><dt>p95</dt><dd>{{ Format::duration($totals->p95 ?? null) }}</dd></div>
                <div><dt>p99</dt><dd>{{ Format::duration($totals->p99 ?? null) }}</dd></div>
            @endif
            <div><dt>Slowest</dt><dd>{{ Format::duration($totals->max ?? null) }}</dd></div>
        </dl>
    </x-laralyze::card>

    @if ($page === 'queries')
        <x-laralyze::card title="SQL" cols="7">
            <pre class="lz-code"><code>{{ Sql::highlight(Sql::format($name)) }}</code></pre>
        </x-laralyze::card>
    @endif
</div>
