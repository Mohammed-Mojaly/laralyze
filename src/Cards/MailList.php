<?php

namespace Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Laralyze\Livewire\Card;
use Livewire\Attributes\Lazy;
use stdClass;

/**
 * Mail sent per mailable, with failures and sending time.
 */
#[Lazy]
class MailList extends Card
{
    public function render(): View
    {
        $sent = $this->aggregate('mail', ['count', 'avg', 'max'])->keyBy('key');
        $failed = $this->counts('mail_failed');

        $mail = $sent->keys()->merge(array_keys($failed))->unique()
            ->map(fn (int|string $name) => (string) $name)
            ->map(function (string $name) use ($sent, $failed) {
                $row = new stdClass;
                $row->name = $name;
                $row->sent = $sent[$name]->count ?? 0.0;
                $row->failed = $failed[$name] ?? 0.0;
                $row->avg = $sent[$name]->avg ?? null;
                $row->max = $sent[$name]->max ?? null;

                return $row;
            })
            ->sortByDesc(fn (stdClass $row) => $row->sent + $row->failed)
            ->values();

        return view('laralyze::cards.mail-list', ['mail' => $mail]);
    }
}
