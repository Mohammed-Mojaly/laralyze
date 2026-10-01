@use('MohammedMojaly\Laralyze\Support\Chart')
@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Servers">
    @if ($servers->isEmpty())
        <x-laralyze::empty
            title="No server has reported yet."
            hint="Each server that runs your scheduler reports its CPU, memory and disks every minute. Make sure your cron entry calls schedule:run."
        />
    @else
        <div class="lz-servers">
            @foreach ($servers as $server)
                @php($stale = $server->seen_at < time() - 300)
                <section @class(['lz-server', 'is-stale' => $stale]) wire:key="{{ $server->name }}">
                    <header>
                        <h3 class="lz-mono">{{ $server->name }}</h3>
                        <span @class(['lz-sub', 'lz-warn' => $stale])>{{ $stale ? 'Not reporting since' : 'Updated' }} <x-laralyze::ago :at="$server->seen_at" /></span>
                    </header>

                    <dl class="lz-gauges">
                        <div>
                            <dt>CPU</dt>
                            <dd>{{ $server->cpu === null ? '—' : round($server->cpu).'%' }}</dd>
                            <x-laralyze::meter :percent="$server->cpu ?? 0" />
                        </div>
                        <div>
                            <dt>Memory</dt>
                            <dd>{{ $server->memory_total ? Format::bytes($server->memory_used).' of '.Format::bytes($server->memory_total) : '—' }}</dd>
                            <x-laralyze::meter :percent="$server->memory_total ? $server->memory_used / $server->memory_total * 100 : 0" />
                        </div>
                        @foreach ($server->disks as $disk)
                            <div>
                                <dt class="lz-mono">{{ $disk['directory'] }}</dt>
                                <dd>{{ Format::bytes($disk['used']) }} of {{ Format::bytes($disk['total']) }}</dd>
                                <x-laralyze::meter :percent="$disk['total'] ? $disk['used'] / $disk['total'] * 100 : 0" />
                            </div>
                        @endforeach
                    </dl>

                    <x-laralyze::lines :chart="new Chart($server->series, $this->range())" />
                </section>
            @endforeach
        </div>
    @endif
</x-laralyze::card>
