{{-- The conversation and the composer, shared by the side panel and the Assistant page. --}}
@use('MohammedMojaly\Laralyze\Support\Brands')
@use('MohammedMojaly\Laralyze\Support\Format')
@if ($active === null)
    <div class="lz-thread">
        <div class="lz-setup">
            <p class="lz-strong">Set up an AI provider to ask about what Laralyze recorded.</p>
            <p>The assistant uses <code>laravel/ai</code> and the providers in <code>config/ai.php</code>. Add a key to <code>.env</code>, for example:</p>
            <pre class="lz-code"><code>OPENAI_API_KEY=sk-...
# or ANTHROPIC_API_KEY, GEMINI_API_KEY, OPENROUTER_API_KEY…</code></pre>
            <p class="lz-muted">Nothing is sent until someone asks a question.</p>
        </div>
    </div>
@else
    <div class="lz-thread" x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)" x-effect="$wire.thinking; $wire.error; $wire.chat; $nextTick(() => $el.scrollTop = $el.scrollHeight)">
        <div class="lz-thread-inner">
            @if ($messages === [] && ! $thinking)
                <div class="lz-welcome">
                    <span class="lz-welcome-mark"><x-laralyze::icon name="ai" /></span>
                    @if ($subject->kind === 'general')
                        <h2>What would you like to know about your app?</h2>
                        <p>Ask about anything Laralyze recorded. Answers come from your data and your code, with charts when they help.</p>
                    @else
                        <h2>Ask about this {{ ['exception' => 'exception', 'n_plus_one' => 'N+1', 'duplicate_query' => 'query', 'query' => 'query', 'request' => 'route', 'execution' => 'run'][$subject->kind] ?? 'issue' }}</h2>
                        <p>The assistant sees what Laralyze recorded about it and can read your code. Pick a question or write your own.</p>
                    @endif

                    @if ($suggestions !== [])
                        <div class="lz-starters">
                            @foreach ($suggestions as $i => [$question, $hint])
                                <button type="button" class="lz-starter" wire:click="suggest({{ $i }})">{{ $question }}<span>{{ $hint }}</span></button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            @foreach ($messages as $i => $message)
                @if ($message['role'] === 'user')
                    <div class="lz-turn lz-turn-user" wire:key="m{{ $i }}">
                        <div class="lz-bubble" dir="auto">{{ $message['content'] }}</div>
                    </div>
                @else
                    <div class="lz-turn lz-turn-ai" wire:key="m{{ $i }}">
                        <span class="lz-avatar" aria-hidden="true"><x-laralyze::icon name="ai" /></span>
                        <div class="lz-turn-body">
                            @foreach ($message['blocks'] as $block)
                                @isset($block['chart'])
                                    @include('laralyze::assistant.chart', ['chart' => $block['chart']])
                                @else
                                    <div class="lz-prose">{!! $block['html'] !!}</div>
                                @endisset
                            @endforeach

                            @if ($message['prompt'])
                                <details class="lz-prompt">
                                    <summary><x-laralyze::icon name="copy" />Prompt for your coding agent</summary>
                                    <textarea id="lz-prompt-{{ $this->getId() }}-{{ $i }}" class="lz-offscreen" readonly tabindex="-1" aria-hidden="true">{{ $message['prompt'] }}</textarea>
                                    <pre class="lz-code"><code>{{ $message['prompt'] }}</code></pre>
                                    <button type="button" class="lz-button" data-laralyze-copy="lz-prompt-{{ $this->getId() }}-{{ $i }}" data-label="Copy prompt"><x-laralyze::icon name="copy" /><span data-label>Copy prompt</span></button>
                                </details>
                            @endif

                            <div class="lz-turn-actions">
                                <textarea id="lz-answer-{{ $this->getId() }}-{{ $i }}" class="lz-offscreen" readonly tabindex="-1" aria-hidden="true">{{ $message['text'] }}</textarea>
                                <button type="button" class="lz-icon-button" data-laralyze-copy="lz-answer-{{ $this->getId() }}-{{ $i }}" title="Copy the answer" aria-label="Copy the answer"><x-laralyze::icon name="copy" /></button>
                                <span class="lz-turn-meta">{{ $message['model'] ?? '' }} · {{ Format::number(($message['in'] ?? 0) + ($message['out'] ?? 0)) }} tokens{{ isset($message['cost']) && $message['cost'] !== null ? ' · '.Format::money($message['cost']) : '' }}</span>
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach

            @if ($thinking)
                <div class="lz-turn lz-turn-ai" wire:key="thinking-{{ count($messages) }}">
                    <span class="lz-avatar" aria-hidden="true"><x-laralyze::icon name="ai" /></span>
                    <div class="lz-turn-body">
                        <p class="lz-status"><span class="lz-dots" aria-hidden="true"><i></i><i></i><i></i></span><span wire:stream="status">Reading what Laralyze recorded…</span></p>
                        <div class="lz-streaming" dir="auto" wire:stream="answer"></div>
                    </div>
                </div>
            @endif

            @if ($error)
                <div class="lz-turn-error" role="alert">
                    <p>{{ $error }}</p>
                    <button type="button" class="lz-button" wire:click="retry">Try again</button>
                </div>
            @endif
        </div>
    </div>

    <div class="lz-composer-wrap">
        <form class="lz-composer" wire:submit="send" x-data="{ text: '' }" x-on:submit="text = ''">
            <textarea
                wire:model="question"
                dir="auto"
                rows="1"
                placeholder="{{ $subject->kind === 'general' ? 'Ask about your app…' : 'Ask a follow-up…' }}"
                aria-label="Your question"
                x-on:input="text = $el.value; $el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 220) + 'px'"
                x-on:keydown.enter="if (! $event.shiftKey && ! $event.isComposing) { $event.preventDefault(); if (text.trim() !== '') $el.form.requestSubmit() }"
                @disabled($thinking)
            ></textarea>

            <div class="lz-composer-bar">
                <span class="lz-composer-model" title="Set with LARALYZE_ASSISTANT_PROVIDER and LARALYZE_ASSISTANT_MODEL">{!! Brands::svg((string) $driver, 'ai') !!}{{ $current }}</span>
                <button type="submit" class="lz-send" title="Send" aria-label="Send" x-bind:disabled="text.trim() === '' || @js($thinking)"><x-laralyze::icon name="send" /></button>
            </div>
        </form>

        <p class="lz-composer-note">Sends your question, what Laralyze recorded and the code it reads to {{ $active }}. Secrets are masked. Kept 7 days.@if ($cost > 0) This conversation: {{ Format::money($cost) }}.@endif</p>
    </div>
@endif
