<?php

use MohammedMojaly\Laralyze\Recorders\Ai;
use MohammedMojaly\Laralyze\Support\AiCall;
use MohammedMojaly\Laralyze\Support\AiPrices;

function prices(): AiPrices
{
    return app(AiPrices::class);
}

it('finds the price of a model under each provider\'s own name for it', function (string $provider, string $model, array $price) {
    expect(array_slice(prices()->for($provider, $model), 0, 2))->toBe($price);
})->with([
    'openai' => ['openai', 'gpt-4o-mini', [0.15, 0.6]],
    'a dated openai model' => ['openai', 'gpt-4o-mini-2024-07-18', [0.15, 0.6]],
    'anthropic, with dashes and a date' => ['anthropic', 'claude-haiku-4-5-20251001', [1.0, 5.0]],
    'gemini is google' => ['gemini', 'gemini-2.5-flash', [0.3, 2.5]],
    'openrouter names' => ['openrouter', 'openai/gpt-4o-mini', [0.15, 0.6]],
    'embeddings' => ['openai', 'text-embedding-3-small', [0.02, 0.0]],
]);

it('knows models run on your own machines are free and unknown ones have no price', function () {
    expect(prices()->for('ollama', 'llama3.1'))->toBe([0.0, 0.0, null, null])
        ->and(prices()->for('openai', 'my-own-model'))->toBeNull()
        ->and(prices()->cost('openai', 'my-own-model', [100, 100, 0, 0]))->toBeNull();
});

it('takes prices from config first', function () {
    config(['laralyze.recorders.'.Ai::class.'.prices' => [
        'my-own-model' => ['input' => 2, 'output' => 8],
        'openai/gpt-4o-mini' => ['input' => 1, 'output' => 1],
    ]]);

    expect(prices()->for('azure', 'my-own-model'))->toBe([2.0, 8.0, null, null])
        ->and(prices()->for('openai', 'gpt-4o-mini'))->toBe([1.0, 1.0, null, null]);
});

it('charges cached input at the cache price', function () {
    config(['laralyze.recorders.'.Ai::class.'.prices' => [
        'cached' => ['input' => 10, 'output' => 20, 'cache_read' => 1, 'cache_write' => 12],
    ]]);

    // 1M in, of which 400K read from the cache and 100K written to it; 500K out.
    expect(prices()->cost('openai', 'cached', [1_000_000, 500_000, 400_000, 100_000]))->toBe(5.0 + 0.4 + 1.2 + 10.0);
});

it('turns OpenRouter\'s list into prices per million tokens, without variants', function () {
    expect(AiPrices::fromOpenRouter(['data' => [
        ['id' => 'openai/gpt-4o-mini', 'pricing' => ['prompt' => '0.00000015', 'completion' => '0.0000006', 'input_cache_read' => '0.000000075']],
        ['id' => 'openai/gpt-4o-mini:batch', 'pricing' => ['prompt' => '0.000000075', 'completion' => '0.0000003']],
        ['id' => '~openai/gpt-latest', 'pricing' => ['prompt' => '0.000001', 'completion' => '0.000001']],
        ['id' => 'openrouter/auto', 'pricing' => ['prompt' => '-1', 'completion' => '-1']],
    ]]))->toBe(['openai/gpt-4o-mini' => [0.15, 0.6, 0.075, null]]);
});

it('reads tokens from laravel/ai 0.x responses too', function () {
    $event = (object) ['response' => (object) ['usage' => (object) ['promptTokens' => 120, 'completionTokens' => 30, 'cacheReadInputTokens' => 20, 'cacheWriteInputTokens' => 0]]];

    expect(AiCall::tokens($event))->toBe([120, 30, 20, 0])
        ->and(AiCall::tokens((object) []))->toBe([0, 0, 0, 0]);
});
