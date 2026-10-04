<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;
use MohammedMojaly\Laralyze\Livewire\Concerns\ListsRows;
use MohammedMojaly\Laralyze\Recorders\Ai;
use stdClass;

/**
 * Every agent, and embeddings, images, audio and transcriptions, with
 * calls, tokens, duration, estimated cost and failures.
 */
#[Lazy]
class AiAgents extends Card
{
    use ListsRows;

    public string $sort = 'count';

    public int $limit = 100;

    public function render(): View
    {
        $timings = $this->aggregate('ai', ['count', 'avg', 'p95'], orderBy: 'count', limit: $this->limit)->keyBy('key');
        $failed = $this->counts('ai_failed');
        [$input, $output, $cost] = [$this->sums('ai_input'), $this->sums('ai_output'), $this->sums('ai_cost')];

        $agents = $timings->keys()->merge(array_keys($failed))->map(fn (int|string $key) => (string) $key)->unique()
            ->map(function (string $key) use ($timings, $failed, $input, $output, $cost) {
                $agent = new stdClass;
                $agent->key = $key;
                $agent->failed = $failed[$key] ?? 0.0;
                $agent->count = ($timings[$key]->count ?? 0) + $agent->failed;
                $agent->avg = $timings[$key]->avg ?? null;
                $agent->p95 = $timings[$key]->p95 ?? null;
                $agent->input = $input[$key] ?? 0.0;
                $agent->output = $output[$key] ?? 0.0;
                $agent->cost = isset($cost[$key]) ? $cost[$key] / Ai::MICRO : null;

                return $agent;
            });

        return view('laralyze::cards.ai-agents', ['agents' => $this->arrange($agents->values())]);
    }

    protected function sortable(): array
    {
        return ['count', 'key', 'input', 'output', 'avg', 'p95', 'cost', 'failed'];
    }
}
