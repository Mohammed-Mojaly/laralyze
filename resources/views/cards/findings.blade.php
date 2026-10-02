@use('MohammedMojaly\Laralyze\Support\Format')
@use('MohammedMojaly\Laralyze\Support\Sql')
<x-laralyze::card :card="$this" title="Findings" :count="$findings->count()">
    @if ($findings->isEmpty())
        <x-laralyze::empty
            :title="'Nothing found in the '.$this->range()->label().'.'"
            hint="Laralyze looks for N+1 queries (the same read again and again with other values) and duplicate queries in every request, job and command."
        />
    @else
        <ol class="lz-findings">
            @foreach ($findings as $finding)
                <li class="lz-finding" wire:key="{{ md5((string) $finding->key) }}">
                    <div class="lz-finding-head">
                        <span @class(['lz-badge', 'lz-badge-bad' => $finding->type === 'n_plus_one', 'lz-badge-warn' => $finding->type !== 'n_plus_one'])>{{ $finding->label }}</span>
                        <span class="lz-mono lz-sub">{{ $finding->location !== '' ? $finding->location : 'location unknown' }}</span>
                        <span class="lz-finding-stats">
                            in <strong>{{ Format::number($finding->count) }}</strong> {{ $finding->count == 1 ? 'execution' : 'executions' }} · up to <strong>{{ Format::number($finding->max) }}×</strong>
                        </span>
                    </div>
                    <pre class="lz-code"><code>{{ Sql::highlight(Sql::format((string) $finding->sql)) }}</code></pre>
                    <p class="lz-finding-hint">{{ $finding->hint }}</p>
                    @if ($finding->example)
                        <a class="lz-button" href="{{ route('laralyze.execution', ['execution' => $finding->example]) }}">See an example</a>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</x-laralyze::card>
