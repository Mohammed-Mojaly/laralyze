<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Livewire\Livewire;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Facades\Laralyze;
use MohammedMojaly\Laralyze\Recorders;
use MohammedMojaly\Laralyze\Recorders\Ai;
use MohammedMojaly\Laralyze\Support\AiPrices;
use MohammedMojaly\Laralyze\Tests\Fixtures\BookWriter;
use MohammedMojaly\Laralyze\Tests\Fixtures\SearchBooks;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
});

/**
 * @param  list<string>  $aggregates
 * @return array<string, stdClass>
 */
function aiRows(string $type, array $aggregates = ['count']): array
{
    return app(Storage::class)->aggregate($type, $aggregates, 3_600)->keyBy('key')->all();
}

function answer(int $in, int $out): TextResponse
{
    // laravel/ai 1.0 has usage classes per kind; 0.x has one for all.
    $usage = class_exists(TextUsage::class) ? new TextUsage($in, $out) : new Usage($in, $out);

    return new TextResponse('A desert planet and a boy who would be emperor.', $usage, new Meta('openai', 'gpt-4o-mini'));
}

function describeDune(): string
{
    return (new BookWriter)->prompt('Describe Dune', provider: 'openai', model: 'gpt-4o-mini')->text;
}

it('records agent calls with tokens, estimated cost and the user', function () {
    BookWriter::fake([answer(1_000_000, 100_000), answer(2_000, 500)]);

    Route::get('/profile', fn () => describeDune());

    $this->actingAs(new GenericUser(['id' => 7, 'name' => 'Sara', 'remember_token' => null]));
    $this->get('/profile')->assertOk();
    auth()->forgetGuards();
    describeDune();
    Laralyze::flush();

    $model = Ai::modelKey('openai', 'gpt-4o-mini');

    // gpt-4o-mini costs $0.15 per million tokens in and $0.60 out.
    expect((float) aiRows('ai')[BookWriter::class]->count)->toBe(2.0)
        ->and((float) aiRows('ai_input', ['sum'])[BookWriter::class]->sum)->toBe(1_002_000.0)
        ->and((float) aiRows('ai_output', ['sum'])[BookWriter::class]->sum)->toBe(100_500.0)
        ->and(round((float) aiRows('ai_cost', ['sum'])[BookWriter::class]->sum / Ai::MICRO, 6))->toBe(0.2106)
        ->and((float) aiRows('ai_model')[$model]->count)->toBe(2.0)
        ->and((float) aiRows('ai_user', ['count', 'sum'])['7']->sum)->toBe(1_100_000.0);

    $this->get('/laralyze/ai')->assertOk();

    Livewire::withoutLazyLoading()->test('laralyze.ai-totals')->assertSee('calls')->assertSee('$0.21');
    Livewire::withoutLazyLoading()->test('laralyze.ai-totals', ['summary' => true])->assertSee('Agents and models')->assertDontSee('Duration');
    $this->get('/laralyze')->assertOk()->assertSee('laralyze.ai-totals');
    Livewire::withoutLazyLoading()->test('laralyze.ai-agents')->assertSee('BookWriter')->assertSee('$0.21');
    Livewire::withoutLazyLoading()->test('laralyze.ai-models')->assertSee('gpt-4o-mini')->assertSee('$0.15 / $0.60');
    Livewire::withoutLazyLoading()->test('laralyze.ai-users')->assertSee('Sara')->assertSee('Jobs, commands and guests');

    $this->get(route('laralyze.group', ['page' => 'ai', 'group' => hash('xxh128', BookWriter::class)]))->assertOk()->assertSee('BookWriter');
    $this->get(route('laralyze.group', ['page' => 'ai', 'group' => hash('xxh128', $model)]))->assertOk()->assertSee('gpt-4o-mini');
    Livewire::withoutLazyLoading()->test('laralyze.group', ['page' => 'ai', 'name' => $model])->assertSee('openai')->assertSee('$0.15 in / $0.60 out')->assertSee('1M');
});

it('never stores prompts or responses', function () {
    BookWriter::fake([answer(10, 10)]);

    (new BookWriter)->prompt('My secret manuscript', provider: 'openai', model: 'gpt-4o-mini');
    Laralyze::flush();

    $storage = app(Storage::class);
    $stored = json_encode([$storage->connection()->table('laralyze_aggregates')->get(), $storage->connection()->table('laralyze_values')->get()]);

    expect($stored)->not->toContain('secret manuscript')->not->toContain('desert planet');
});

it('counts calls that threw as failed', function () {
    BookWriter::fake(fn () => throw new RuntimeException('Rate limited'));

    expect(fn () => describeDune())->toThrow(RuntimeException::class);
    Laralyze::flush();

    expect((float) aiRows('ai_failed')[BookWriter::class]->count)->toBe(1.0)
        ->and((float) aiRows('ai_model_failed')[Ai::modelKey('openai', 'gpt-4o-mini')]->count)->toBe(1.0)
        ->and(aiRows('ai'))->toBe([]);
});

it('records embeddings as an operation of their own', function () {
    Embeddings::fake();

    Embeddings::for(['Dune', 'Emma'])->generate('openai', 'text-embedding-3-small');
    Laralyze::flush();

    expect((float) aiRows('ai')['Embeddings']->count)->toBe(1.0)
        ->and(aiRows('ai_model'))->toHaveKey(Ai::modelKey('openai', 'text-embedding-3-small'));
});

it('leaves out agents matching the ignore patterns', function () {
    $this->rebootWith(['laralyze.recorders' => [Ai::class => ['enabled' => true, 'ignore' => ['/BookWriter$/']]]]);

    BookWriter::fake([answer(10, 10)]);
    describeDune();
    Laralyze::flush();

    expect(aiRows('ai'))->toBe([]);
});

it('puts AI calls and the tools they used in the timeline, where they started', function () {
    $this->rebootWith(['laralyze.recorders' => [
        Recorders\Traces::class => ['enabled' => true, 'sample_rate' => 1],
        Ai::class => ['enabled' => true],
    ]]);
    app()->detectEnvironment(fn () => 'local');

    // laravel/ai's fake answers with tool calls from 1.0.
    $tools = class_exists(TextUsage::class);
    BookWriter::fake($tools ? [new ToolCall('call-1', 'SearchBooks', []), answer(4_210, 980)] : [answer(4_210, 980)]);

    Route::get('/profile', fn () => describeDune());
    $this->get('/profile')->assertOk();
    Laralyze::flush();

    $storage = app(Storage::class);
    $execution = $storage->execution($storage->executions([], 3_600)->sole()->uuid);
    $events = array_values(array_filter($execution->events, fn (array $event) => in_array($event[0], ['ai', 'tool'], true)));

    expect(array_column($events, 0))->toBe($tools ? ['ai', 'tool'] : ['ai'])
        ->and($events[0][3])->toBe(BookWriter::class)
        ->and(json_decode($events[0][4], true))->toBe(['openai', 'gpt-4o-mini', 4_210, 980, false])
        ->and($execution->counts['ai'])->toBe(1);

    $page = $this->get('/laralyze/executions/'.$execution->uuid)->assertOk()->assertSee('AI calls')->assertSee('4,210');

    if ($tools) {
        expect($events[1][3])->toBe(SearchBooks::class);
        $page->assertSee('SearchBooks');
    }
});

it('fetches prices from OpenRouter and uses them', function () {
    Http::fake(['openrouter.ai/*' => Http::response(['data' => [
        ['id' => 'acme/rocket-1', 'pricing' => ['prompt' => '0.000001', 'completion' => '0.000004']],
    ]])]);

    $this->artisan('laralyze:ai-prices')->assertSuccessful();

    expect(app(AiPrices::class)->for('openrouter', 'acme/rocket-1'))->toBe([1.0, 4.0, null, null]);
});
