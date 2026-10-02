@use('MohammedMojaly\Laralyze\Support\Chart')
@use('MohammedMojaly\Laralyze\Support\Format')
@php
    $at = fn (?float $timestamp) => $timestamp === null ? '—' : now()->setTimestamp((int) $timestamp);
    $total = array_sum($counts);
@endphp
<div class="lz-span-full lz-grid">
    <x-laralyze::card title="Info" cols="6">
        <dl class="lz-info">
            <div><dt>Last seen</dt><dd>@if ($lastSeen)<span title="{{ $at($lastSeen)->toDayDateTimeString() }}">{{ $at($lastSeen)->diffForHumans() }}</span>@else — @endif</dd></div>
            <div><dt>First seen</dt><dd>@if ($firstSeen)<span title="{{ $at($firstSeen)->toDayDateTimeString() }}">{{ $at($firstSeen)->diffForHumans() }}</span>@else — @endif</dd></div>
            <div><dt>Source</dt><dd>@if (is_array($exception->source))<span class="lz-badge">{{ $exception->source['type'] }}</span> <span class="lz-mono">{{ $exception->source['name'] }}</span>@else — @endif</dd></div>
            <div><dt>Impacted users</dt><dd>{{ Format::number($users) }}</dd></div>
            <div><dt>Occurrences</dt><dd>24 hours <span class="lz-badge">{{ Format::number($lastDay) }}</span> 7 days <span class="lz-badge">{{ Format::number($lastWeek) }}</span></dd></div>
            <div><dt>Handled / unhandled</dt><dd>{{ Format::number($counts['handled']) }} / <span @class(['lz-bad' => $counts['unhandled'] > 0])>{{ Format::number($counts['unhandled']) }}</span></dd></div>
            <div><dt>Server</dt><dd>{{ $exception->server ?? '—' }}</dd></div>
            <div><dt>PHP / Laravel</dt><dd>{{ $exception->php ?? '—' }} / {{ $exception->laravel ?? '—' }}</dd></div>
        </dl>
    </x-laralyze::card>

    <x-laralyze::card title="Occurrences" cols="6">
        @if ($total > 0)
            <div class="lz-figures">
                <x-laralyze::figure :value="Format::number($total)" :label="$total == 1 ? 'occurrence' : 'occurrences'" />
                <x-laralyze::legend :items="$counts" :total="$total" />
            </div>

            <x-laralyze::bars :chart="new Chart($series, $this->range())" />
        @else
            <x-laralyze::empty :title="'Not seen in the '.$this->range()->label().'.'" hint="Pick a longer period to see older occurrences." />
        @endif
    </x-laralyze::card>

    <x-laralyze::card cols="full" class="lz-exception">
        <header class="lz-exception-head">
            <div class="lz-exception-tags">
                @if ($counts['unhandled'] > 0)
                    <span class="lz-badge lz-badge-bad">Unhandled</span>
                @else
                    <span class="lz-badge">Handled</span>
                @endif
                @if ($exception->code !== null)
                    <span class="lz-badge">{{ $exception->code }}</span>
                @endif
            </div>

            <div class="lz-exception-tools">
                <textarea id="lz-markdown-{{ $this->getId() }}" class="lz-offscreen" readonly tabindex="-1" aria-hidden="true">{{ $markdown }}</textarea>
                <button type="button" class="lz-button" data-laralyze-copy="lz-markdown-{{ $this->getId() }}" data-label="Copy as Markdown"><x-laralyze::icon name="copy" /><span data-label>Copy as Markdown</span></button>
                @if ($exception->php)
                    <span class="lz-badge">PHP {{ $exception->php }}</span>
                    <span class="lz-badge">Laravel {{ $exception->laravel }}</span>
                @endif
            </div>
        </header>

        <h2 class="lz-exception-class">{{ $exception->class }}</h2>
        <p class="lz-exception-message">{{ $exception->message }}</p>

        @if ($exception->frames === [])
            <x-laralyze::empty title="No stack trace yet." hint="It's kept from the next time this exception happens." />
        @else
            <ol class="lz-frames">
                @foreach ($groups as $group)
                    @if (! $group['app'])
                        <li>
                            <details class="lz-frame-group">
                                <summary><x-laralyze::icon name="folder" />{{ count($group['frames']) }} vendor {{ count($group['frames']) === 1 ? 'frame' : 'frames' }}</summary>
                                <ol>
                                    @foreach ($group['frames'] as $frame)
                                        <li class="lz-frame-line">
                                            <span class="lz-frame-call">{{ $frame['call'] === '{main}' ? 'Entrypoint' : $frame['call'] }}</span>
                                            <span class="lz-frame-file">{{ $frame['file'] }}:{{ $frame['line'] }}</span>
                                        </li>
                                    @endforeach
                                </ol>
                            </details>
                        </li>
                    @else
                        @php($frame = $group['frames'][0])
                        <li>
                            <details class="lz-frame" @if ($group['open']) open @endif>
                                <summary class="lz-frame-line">
                                    <span class="lz-frame-call">{{ $frame['call'] }}</span>
                                    <span class="lz-frame-file">{{ $frame['file'] }}:{{ $frame['line'] }}</span>
                                </summary>
                                @isset($frame['code'])
                                    <pre class="lz-frame-code"><code>@foreach ($frame['code']['lines'] as $offset => $code)<span @class(['lz-code-line', 'is-current' => $frame['code']['start'] + $offset === $frame['line']])><span class="lz-code-number">{{ $frame['code']['start'] + $offset }}</span>{{ $code }}</span>@endforeach</code></pre>
                                @endisset
                            </details>
                        </li>
                    @endif
                @endforeach
            </ol>
        @endif
    </x-laralyze::card>
</div>
