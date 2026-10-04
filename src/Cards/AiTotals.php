<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;
use MohammedMojaly\Laralyze\Recorders\Ai;

/**
 * AI calls over time with their tokens, estimated cost, failures and
 * how long they took.
 */
#[Lazy]
class AiTotals extends Card
{
    /**
     * Only the calls, with a link to the AI page, as on the dashboard.
     */
    public bool $summary = false;

    public function render(): View
    {
        $totals = $this->total('ai', ['count', 'avg', 'p95', 'max']);
        $failed = (float) ($this->total('ai_failed', ['count'])->count ?? 0);
        $ok = (float) ($totals->count ?? 0);

        return view('laralyze::cards.ai-totals', [
            'calls' => $ok + $failed,
            'failed' => $failed,
            'totals' => $totals,
            'input' => (float) ($this->total('ai_input', ['sum'])->sum ?? 0),
            'output' => (float) ($this->total('ai_output', ['sum'])->sum ?? 0),
            'cost' => (float) ($this->total('ai_cost', ['sum'])->sum ?? 0) / Ai::MICRO,
            'series' => ['calls' => $this->graph('ai', 'count'), 'failed' => $this->graph('ai_failed', 'count')],
            'tokens' => ['input' => $this->graph('ai_input', 'sum'), 'output' => $this->graph('ai_output', 'sum')],
            'durations' => ['avg' => $this->graph('ai', 'avg'), 'p95' => $this->graph('ai', 'p95')],
        ]);
    }
}
