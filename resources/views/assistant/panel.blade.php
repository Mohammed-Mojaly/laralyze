<aside @class(['lz-assistant', 'is-open' => $open]) aria-label="Ask AI" @if (! $open) hidden @endif x-data x-on:keydown.escape.window="$wire.open && $wire.close()">
    <header class="lz-assistant-head">
        <h2><x-laralyze::icon name="ai" /> Ask AI</h2>

        <div class="lz-assistant-actions">
            @if ($messages !== [])
                <button type="button" class="lz-icon-button" wire:click="newChat" title="New conversation about this" aria-label="New conversation about this"><x-laralyze::icon name="new" /></button>
            @endif
            <a class="lz-icon-button" href="{{ route('laralyze.page', array_filter(['page' => 'assistant', 'chat' => $chat])) }}" title="Open the Assistant page" aria-label="Open the Assistant page"><x-laralyze::icon name="expand" /></a>
            <button type="button" class="lz-icon-button" wire:click="close" title="Close" aria-label="Close"><x-laralyze::icon name="close" /></button>
        </div>
    </header>

    @if ($subject->kind !== 'general')
        <div class="lz-assistant-subject">
            <span class="lz-chip" title="{{ $subject->label }}">{{ $subject->label }}</span>
        </div>
    @endif

    @include('laralyze::assistant.chat')
</aside>
