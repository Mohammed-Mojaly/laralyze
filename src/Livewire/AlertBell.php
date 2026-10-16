<?php

namespace MohammedMojaly\Laralyze\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use MohammedMojaly\Laralyze\Alerts\History;

/**
 * The bell in the top bar: the latest alerts, failed deliveries first,
 * and how many came since the viewer last opened it.
 */
class AlertBell extends Component
{
    use Concerns\AuthorizesAccess;

    public function markSeen(): void
    {
        app(History::class)->markSeen($this->viewer(), now()->getTimestamp());
    }

    public function render(): View
    {
        $history = app(History::class);
        $entries = $history->bell();
        $seen = $history->seenAt($this->viewer());

        return view('laralyze::alerts.bell', [
            'entries' => $entries,
            'unread' => count(array_filter($entries, fn (array $entry) => (int) $entry['at'] > $seen)),
        ]);
    }

    protected function viewer(): ?string
    {
        $id = auth()->id();

        return $id === null ? null : (string) $id;
    }
}
