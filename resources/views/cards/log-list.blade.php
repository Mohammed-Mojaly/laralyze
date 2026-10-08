<x-laralyze::card :card="$this" title="Entries" :count="$logs->count()">
    <x-slot:actions>
        <x-laralyze::search placeholder="Search messages" />

        <div class="lz-dropdown" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
            <button type="button" class="lz-dropdown-button" x-on:click="open = ! open" x-bind:aria-expanded="open">
                <span class="lz-dropdown-label">Level</span>
                {{ $levels === [] ? 'All' : (count($levels) === 1 ? ucfirst($levels[0]) : count($levels).' levels') }}
                <span class="lz-dropdown-caret" aria-hidden="true">▾</span>
            </button>
            <div class="lz-dropdown-menu" x-show="open" x-cloak>
                @foreach ($levelOptions as $level)
                    <label class="lz-check">
                        <input type="checkbox" value="{{ $level }}" wire:model.live="levels">
                        <span class="lz-swatch lz-s-{{ $level }}"></span>{{ ucfirst($level) }}
                    </label>
                @endforeach
                <button type="button" class="lz-button" wire:click="$set('levels', [])" @disabled($levels === [])>Clear</button>
            </div>
        </div>

        <label class="lz-dropdown-button lz-select">
            <span class="lz-dropdown-label">User</span>
            <select wire:model.live="user">
                <option value="">All</option>
                @foreach ($userIds as $id)
                    <option value="{{ $id }}">{{ $users[$id] ?? $id }}</option>
                @endforeach
            </select>
            <span class="lz-dropdown-caret" aria-hidden="true">▾</span>
        </label>
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
                <li wire:key="{{ $log->uuid }}" @class(['lz-log', 'is-open' => $isOpen])>
                    <button type="button" class="lz-log-row" wire:click="toggle('{{ $log->uuid }}')" aria-expanded="{{ $isOpen ? 'true' : 'false' }}">
                        <span class="lz-log-at"><x-laralyze::ago :at="$log->logged_at" /></span>
                        <span class="lz-log-level lz-s-{{ $log->level }}">{{ $log->level }}</span>
                        <span class="lz-log-source">
                            @if ($log->type !== null)
                                <span class="lz-badge">{{ $log->type }}</span> <span class="lz-log-name">{{ $log->name }}</span>
                            @endif
                        </span>
                        <span class="lz-log-message">{{ $log->message }}</span>
                    </button>

                    @if ($isOpen)
                        <div class="lz-log-detail">
                            <pre class="lz-code">{{ $log->message }}</pre>

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
                                <h3 class="lz-log-heading">Context</h3>
                                <pre class="lz-code">{{ json_encode($log->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                            @endif
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
