<?php

namespace MohammedMojaly\Laralyze\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use MohammedMojaly\Laralyze\Alerts\History;
use MohammedMojaly\Laralyze\Laralyze;

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

        // A storage blip shows an empty bell until the next poll, not an error.
        [$entries, $seen] = app(Laralyze::class)->rescue(fn () => [$history->bell(), $history->seenAt($this->viewer())], [[], 0]);

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
