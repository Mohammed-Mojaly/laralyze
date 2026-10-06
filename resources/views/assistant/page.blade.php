@php
    $groups = [];
    foreach ($chats as $item) {
        $day = \Illuminate\Support\Carbon::createFromTimestamp($item['at'], (string) config('app.timezone', 'UTC'));
        $item['time'] = $day->isToday() || $day->isYesterday() ? $day->format('H:i') : $day->format('D H:i');
        $groups[match (true) { $day->isToday() => 'Today', $day->isYesterday() => 'Yesterday', default => 'Earlier this week' }][] = $item;
    }
    $icons = ['general' => 'ai', 'exception' => 'exceptions', 'n_plus_one' => 'findings', 'duplicate_query' => 'findings', 'query' => 'queries', 'request' => 'requests', 'execution' => 'jobs'];
@endphp
<div
    class="lz-assistant-page"
    {{-- On a phone the list covers the chat, so it starts closed there and the choice isn't remembered. --}}
    x-data="{ phone: matchMedia('(max-width: 900px)').matches, list: false }"
    x-init="list = ! phone && (() => { try { return localStorage.getItem('laralyze.chats') !== 'closed' } catch { return true } })(); $watch('list', (open) => { if (phone) return; try { localStorage.setItem('laralyze.chats', open ? 'open' : 'closed') } catch {} })"
    x-effect="const url = new URL(location.href); $wire.chat ? url.searchParams.set('chat', $wire.chat) : url.searchParams.delete('chat'); if (url.href !== location.href) history.replaceState(history.state, '', url)"
    x-bind:class="{ 'is-list-closed': ! list }"
>
    <aside class="lz-chat-list" aria-label="Conversations">
        <div class="lz-chat-list-head">
            <h2>Conversations @if ($chats !== [])<span>{{ count($chats) }}</span>@endif</h2>
            <button type="button" class="lz-icon-button" x-on:click="list = false" title="Hide conversations" aria-label="Hide conversations"><x-laralyze::icon name="sidebar" /></button>
        </div>

        <button type="button" class="lz-chat-new" wire:click="start" x-on:click="phone && (list = false)"><x-laralyze::icon name="new" />New conversation</button>

        <div class="lz-chat-groups">
            @forelse ($groups as $label => $items)
                <section class="lz-chat-group">
                    <h3>{{ $label }}</h3>
                    @foreach ($items as $item)
                        <div @class(['lz-chat-row', 'is-active' => $item['id'] === $chat]) wire:key="c{{ $item['id'] }}">
                            <button type="button" class="lz-chat-link" wire:click="show(@js($item['id']))" x-on:click="phone && (list = false)" title="{{ $item['title'] }}">
                                <span class="lz-chat-kind"><x-laralyze::icon :name="$icons[$item['kind']] ?? 'ai'" /></span>
                                <span class="lz-chat-text">
                                    <span class="lz-chat-title" dir="auto">{{ $item['title'] }}</span>
                                    <span class="lz-chat-label">{{ $item['kind'] !== 'general' ? $item['label'] : $item['time'] }}</span>
                                </span>
                            </button>
                            <button type="button" class="lz-icon-button" wire:click="forget(@js($item['id']))" wire:confirm="Delete this conversation?" title="Delete" aria-label="Delete"><x-laralyze::icon name="close" /></button>
                        </div>
                    @endforeach
                </section>
            @empty
                <div class="lz-chats-hint">
                    <x-laralyze::icon name="ai" />
                    <p>No conversations yet.</p>
                    <p>Ask about your app here, or use Ask AI on an exception, a finding, a query or a request.</p>
                </div>
            @endforelse
        </div>

        <p class="lz-chat-list-foot">Conversations are kept for 7 days.</p>
    </aside>

    <section class="lz-chat-main" aria-label="Conversation">
        <header class="lz-chat-top">
            <button type="button" class="lz-icon-button" x-show="! list" x-on:click="list = true" title="Show conversations" aria-label="Show conversations"><x-laralyze::icon name="sidebar" /></button>
            <button type="button" class="lz-icon-button" x-show="! list" wire:click="start" title="New conversation" aria-label="New conversation"><x-laralyze::icon name="new" /></button>

            @if ($subject->kind !== 'general')
                <span class="lz-chip" title="{{ $subject->label }}">{{ $subject->label }}</span>
            @endif

            <span class="lz-chat-top-end">
                @if ($messages !== [] && $subject->kind !== 'general')
                    <button type="button" class="lz-icon-button" wire:click="newChat" title="New conversation about this" aria-label="New conversation about this"><x-laralyze::icon name="new" /></button>
                @endif
                <x-laralyze::theme-toggle />
            </span>
        </header>

        @include('laralyze::assistant.chat')
    </section>
</div>
