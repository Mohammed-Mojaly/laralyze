@use('MohammedMojaly\Laralyze\Support\Json')
<x-laralyze::card :card="$this" title="Entries" :count="$logs->count()">
    <x-slot:actions>
        <x-laralyze::search placeholder="Search messages" />

        <div class="lz-dropdown" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
            <button type="button" class="lz-dropdown-button" x-on:click="open = ! open" x-bind:aria-expanded="open">
                <span class="lz-dropdown-label">Level</span>
                {{ $levelLabel }}
                <span class="lz-dropdown-caret" aria-hidden="true">▾</span>
            </button>
            <div class="lz-dropdown-menu" x-show="open" x-cloak>
                <div class="lz-dropdown-presets">
                    <button type="button" class="lz-button" wire:click="atLeast('warning')" x-on:click="open = false">Warning and above</button>
                    <button type="button" class="lz-button" wire:click="atLeast('error')" x-on:click="open = false">Error and above</button>
                </div>
                @foreach ($levelOptions as $level)
                    <label class="lz-check">
                        <input type="checkbox" value="{{ $level }}" wire:model.live="levels">
                        <span class="lz-swatch lz-s-{{ $level }}"></span>{{ ucfirst($level) }}
                    </label>
                @endforeach
                <button type="button" class="lz-button" wire:click="$set('levels', [])" @disabled($levels === [])>Clear</button>
            </div>
        </div>

        <div class="lz-dropdown" x-data="{ open: false, find: '' }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
            <button type="button" class="lz-dropdown-button" x-on:click="open = ! open; $nextTick(() => $refs.find?.focus())" x-bind:aria-expanded="open" aria-haspopup="true">
                <span class="lz-dropdown-label">User</span>
                <span class="lz-dropdown-value">{{ $user === '' ? 'All' : ($users[$user] ?? $user) }}</span>
                <span class="lz-dropdown-caret" aria-hidden="true">▾</span>
            </button>
            <div class="lz-dropdown-menu lz-user-menu" x-show="open" x-cloak>
                @if (count($userIds) > 6)
                    <label class="lz-search">
                        <x-laralyze::icon name="search" />
                        <input type="search" x-ref="find" x-model="find" placeholder="Find a user" aria-label="Find a user">
                    </label>
                @endif
                <div class="lz-user-options">
                    <button type="button" @class(['lz-option', 'is-active' => $user === '']) wire:click="$set('user', '')" x-on:click="open = false">All users</button>
                    @foreach ($userIds as $id)
                        @php($name = $users[$id] ?? (string) $id)
                        <button type="button" @class(['lz-option', 'is-active' => $user === (string) $id]) wire:click="$set('user', @js((string) $id))" x-on:click="open = false" x-show="find === '' || @js(mb_strtolower($name.' #'.$id)).includes(find.toLowerCase())">
                            <span class="lz-user-option">{{ $name }}@if ($name !== (string) $id) <span class="lz-muted">#{{ $id }}</span>@endif</span>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    </x-slot:actions>

    @if ($logs->isEmpty())
        <x-laralyze::empty
            :title="'No entries in the '.$this->range()->label().($filtered ? ' with these filters' : '').'.'"
            hint="Messages at or above LARALYZE_LOGS_LEVEL (info by default) are kept here; every level is counted above."
        />
    @else
        <ul class="lz-logs">
            @foreach ($logs as $log)
                @php($isOpen = $opened?->uuid === $log->uuid)
                <li wire:key="{{ $log->uuid }}" @class(['lz-log', "lz-s-{$log->level}", 'is-open' => $isOpen])>
                    <button type="button" class="lz-log-row" wire:click="toggle('{{ $log->uuid }}')" aria-expanded="{{ $isOpen ? 'true' : 'false' }}">
                        <span class="lz-log-at"><x-laralyze::ago :at="$log->logged_at" /></span>
                        <span class="lz-log-level">{{ $log->level }}</span>
                        <span class="lz-log-main">
                            <span class="lz-log-message">{{ $log->message }}</span>
                            @if ($log->type !== null)
                                <span class="lz-log-source">{{ $log->type }} · {{ $log->name }}</span>
                            @endif
                        </span>
                        <span class="lz-log-marks">
                            @if ($log->exception !== null)
                                <span class="lz-log-mark is-bad" title="Reported an exception"><x-laralyze::icon name="exceptions" /></span>
                            @endif
                            @if ($log->context !== null)
                                <span class="lz-log-mark" title="Has context">{ }</span>
                            @endif
                        </span>
                    </button>

                    @if ($isOpen)
                        @php($full = str_contains($log->message, "\n") || mb_strlen($log->message) > 120)
                        <div class="lz-log-detail">
                            @if ($full)
                                <pre class="lz-code lz-log-full">{{ $log->message }}</pre>
                            @endif

                            <dl class="lz-log-facts">
                                <div>
                                    <dt>When</dt>
                                    <dd>{{ \Illuminate\Support\Carbon::createFromTimestamp($log->logged_at, (string) config('app.timezone', 'UTC'))->format('M j, Y H:i:s') }}</dd>
                                </div>
                                @if ($log->type !== null)
                                    <div>
                                        <dt>Written in</dt>
                                        <dd>
                                            <span class="lz-badge">{{ $log->type }}</span>
                                            @if ($kept)
                                                <a href="{{ route('laralyze.execution', ['execution' => $log->execution, 'period' => $this->period]) }}" class="lz-mono">{{ $log->name }} ↗</a>
                                            @else
                                                <span class="lz-mono">{{ $log->name }}</span>
                                            @endif
                                        </dd>
                                    </div>
                                @endif
                                @if ($log->exception !== null)
                                    <div>
                                        <dt>Exception</dt>
                                        <dd><a href="{{ route('laralyze.group', ['page' => 'exceptions', 'group' => $log->exception, 'period' => $this->period]) }}">Open the exception ↗</a></dd>
                                    </div>
                                @endif
                                @if ($log->user_id !== null)
                                    <div>
                                        <dt>User</dt>
                                        <dd>{{ $users[$log->user_id] ?? $log->user_id }}</dd>
                                    </div>
                                @endif
                                <div>
                                    <dt>Server</dt>
                                    <dd>{{ $log->server }}</dd>
                                </div>
                            </dl>

                            @if ($log->context !== null)
                                <div class="lz-log-context">
                                    <h3 class="lz-log-heading">Context</h3>
                                    @if (is_array($log->context) && $log->context !== [] && ! array_is_list($log->context))
                                        <table class="lz-log-pairs">
                                            @foreach ($log->context as $key => $value)
                                                <tr>
                                                    <th scope="row">{{ $key }}</th>
                                                    <td>{{ Json::cell($value) }}</td>
                                                </tr>
                                            @endforeach
                                        </table>
                                    @else
                                        <pre class="lz-code lz-json">{{ Json::highlight($log->context) }}</pre>
                                    @endif
                                </div>
                            @endif

                            <div class="lz-log-actions">
                                <textarea id="lz-log-message-{{ $log->uuid }}" class="lz-offscreen" readonly tabindex="-1" aria-hidden="true">{{ $log->message }}</textarea>
                                <button type="button" class="lz-button" data-laralyze-copy="lz-log-message-{{ $log->uuid }}" data-label="Copy message"><x-laralyze::icon name="copy" /><span data-label>Copy message</span></button>
                                @if ($log->context !== null)
                                    <textarea id="lz-log-context-{{ $log->uuid }}" class="lz-offscreen" readonly tabindex="-1" aria-hidden="true">{{ Json::pretty($log->context) }}</textarea>
                                    <button type="button" class="lz-button" data-laralyze-copy="lz-log-context-{{ $log->uuid }}" data-label="Copy context"><x-laralyze::icon name="copy" /><span data-label>Copy context</span></button>
                                @endif
                            </div>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>

        @if ($current > 1 || $more)
            <nav class="lz-pager" aria-label="Pages">
                <button type="button" class="lz-button" wire:click="previousPage" @disabled($current <= 1)>‹ Previous</button>
                <span class="lz-muted">Page {{ $current }}</span>
                <button type="button" class="lz-button" wire:click="nextPage" @disabled(! $more)>Next ›</button>
            </nav>
        @endif
    @endif
</x-laralyze::card>
