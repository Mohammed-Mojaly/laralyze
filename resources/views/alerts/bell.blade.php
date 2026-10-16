@use('MohammedMojaly\Laralyze\Alerts\History')
@php
    $events = [History::FIRED => 'Fired', History::RECOVERED => 'Back to normal', History::REMINDER => 'Reminder', History::TEST => 'Test'];
@endphp
<div class="lz-dropdown lz-bell" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false" wire:poll.visible.30s>
    <button type="button" class="lz-icon-button" x-on:click="open = ! open; if (open) $wire.markSeen()" x-bind:aria-expanded="open" aria-label="Alerts{{ $unread > 0 ? ", {$unread} new" : '' }}">
        <x-laralyze::icon name="alerts" />
        @if ($unread > 0)
            <span class="lz-bell-count">{{ $unread >= History::BELL ? History::BELL.'+' : $unread }}</span>
        @endif
    </button>

    <div class="lz-dropdown-menu lz-bell-menu" x-show="open" x-cloak>
        @forelse ($entries as $entry)
            <a class="lz-bell-entry" href="{{ route('laralyze.page', ['page' => 'alerts', 'tab' => 'history']) }}#entry-{{ $entry['key'] }}">
                <span class="lz-bell-title">{{ $entry['title'] }}</span>
                <span class="lz-bell-meta">
                    {{ $events[$entry['event']] ?? $entry['event'] }} · <x-laralyze::ago :at="(int) $entry['at']" />
                    @if (History::failed($entry))
                        · <span class="lz-bell-failed">not delivered</span>
                    @endif
                </span>
            </a>
        @empty
            <p class="lz-bell-empty">No alerts yet.</p>
        @endforelse

        <a class="lz-bell-all" href="{{ route('laralyze.page', ['page' => 'alerts']) }}">Alert rules</a>
    </div>
</div>
