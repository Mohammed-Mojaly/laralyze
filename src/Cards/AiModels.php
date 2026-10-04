<?php

namespace MohammedMojaly\Laralyze\Cards;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;
use MohammedMojaly\Laralyze\Livewire\Concerns\ListsRows;
use MohammedMojaly\Laralyze\Recorders\Ai;
use MohammedMojaly\Laralyze\Support\AiPrices;
use stdClass;

/**
 * Every model, by provider, with calls, tokens, estimated cost and its
 * price per million tokens.
 */
#[Lazy]
class AiModels extends Card
{
    use ListsRows;

    public string $sort = 'count';

    public int $limit = 100;

    public function render(): View
    {
        $timings = $this->aggregate('ai_model', ['count', 'avg'], orderBy: 'count', limit: $this->limit)->keyBy('key');
        $failed = $this->counts('ai_model_failed');
        [$input, $output, $cost] = [$this->sums('ai_model_input'), $this->sums('ai_model_output'), $this->sums('ai_model_cost')];
        $prices = app(AiPrices::class);

        $models = $timings->keys()->merge(array_keys($failed))->map(fn (int|string $key) => (string) $key)->unique()
            ->map(function (string $key) use ($timings, $failed, $input, $output, $cost, $prices) {
                $model = new stdClass;
                $model->key = $key;
                [$model->provider, $model->name] = array_pad($this->parts($key), 2, '');
                $model->failed = $failed[$key] ?? 0.0;
                $model->count = ($timings[$key]->count ?? 0) + $model->failed;
                $model->avg = $timings[$key]->avg ?? null;
                $model->tokens = ($input[$key] ?? 0) + ($output[$key] ?? 0);
                $model->cost = isset($cost[$key]) ? $cost[$key] / Ai::MICRO : null;
                $model->price = $prices->for($model->provider, $model->name);
                $model->search = "{$model->provider} {$model->name}";

                return $model;
            });

        return view('laralyze::cards.ai-models', ['models' => $this->arrange($models->values(), 'search')]);
    }

    protected function sortable(): array
    {
        return ['count', 'name', 'tokens', 'avg', 'cost', 'failed'];
    }

    /**
     * @return list<string>
     */
    protected function textColumns(): array
    {
        return ['name'];
    }
}
