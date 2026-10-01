<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;
use stdClass;

/**
 * Notifications sent per class and channel, with failures.
 */
#[Lazy]
class NotificationList extends Card
{
    public function render(): View
    {
        $sent = $this->aggregate('notification', ['count', 'avg', 'max'])->keyBy('key');
        $failed = $this->counts('notification_failed');

        $notifications = $sent->keys()->merge(array_keys($failed))->unique()
            ->map(fn (int|string $key) => (string) $key)
            ->map(function (string $key) use ($sent, $failed) {
                $row = new stdClass;
                [$row->name, $row->channel] = array_pad($this->parts($key), 2, '');
                $row->sent = $sent[$key]->count ?? 0.0;
                $row->failed = $failed[$key] ?? 0.0;
                $row->avg = $sent[$key]->avg ?? null;

                return $row;
            })
            ->sortByDesc(fn (stdClass $row) => $row->sent + $row->failed)
            ->values();

        return view('laralyze::cards.notification-list', ['notifications' => $notifications]);
    }
}
