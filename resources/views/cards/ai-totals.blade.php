@use('MohammedMojaly\Laralyze\Support\Chart')
@use('MohammedMojaly\Laralyze\Support\Format')
{{-- The dashboard leaves it out until there are calls, still polling; the AI page stays in the sidebar. --}}
<div class="lz-span-full lz-grid" {!! $summary && $calls == 0 ? 'hidden wire:poll.30s' : ($this->poll > 0 ? 'wire:poll.visible.'.$this->poll.'s' : '') !!}>
    <x-laralyze::card title="AI calls" cols="full">
        @if ($summary)
            <x-slot:actions>
                <a class="lz-button" href="{{ route('laralyze.page', ['page' => 'ai', ...($this->period === '1h' ? [] : ['period' => $this->period])]) }}">Agents and models</a>
            </x-slot:actions>
        @endif

        @if ($calls > 0)
            <div class="lz-figures">
                <x-laralyze::figure :value="Format::number($calls)" :label="$calls == 1 ? 'call' : 'calls'" />
                <x-laralyze::figure :value="Format::number($input + $output)" label="tokens" />
                <x-laralyze::figure :value="Format::money($cost)" label="estimated cost" title="From each model's price per token. Models without a known price aren't included." />
                <x-laralyze::figure @class(['lz-figure-bad' => $failed > 0]) :value="Format::number($failed)" :label="'failed · '.Format::percent($failed, $calls)" />
            </div>

            <x-laralyze::bars :chart="new Chart($series, $this->range())" />
        @else
            <x-laralyze::empty
                :title="'No AI calls in the '.$this->range()->label().'.'"
                hint="Agents, embeddings, images, audio and transcriptions made with laravel/ai appear here. Prompts and responses are never recorded."
            />
        @endif
    </x-laralyze::card>

    @if ($calls > 0 && ! $summary)
        <x-laralyze::card title="Tokens" cols="6">
            <x-laralyze::legend :items="['input' => $input, 'output' => $output]" :total="$input + $output" />
            <x-laralyze::bars :chart="new Chart($tokens, $this->range())" />
        </x-laralyze::card>

        <x-laralyze::card title="Duration" cols="6">
            <dl class="lz-legend">
                <div class="lz-legend-item lz-s-avg"><dt><span class="lz-swatch"></span>avg</dt><dd>{{ Format::duration($totals->avg ?? null) }}</dd></div>
                <div class="lz-legend-item lz-s-p95"><dt><span class="lz-swatch"></span>p95</dt><dd>{{ Format::duration($totals->p95 ?? null) }}</dd></div>
                <div class="lz-legend-item"><dt>slowest</dt><dd>{{ Format::duration($totals->max ?? null) }}</dd></div>
            </dl>
            <x-laralyze::lines :chart="new Chart($durations, $this->range())" format="duration" />
        </x-laralyze::card>
    @endif
</div>
