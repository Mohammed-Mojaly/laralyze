<?php

namespace MohammedMojaly\Laralyze\Support;

/**
 * Reads laravel/ai's events, from 0.6 to 1.x, without requiring the
 * package: what was called, on which provider and model, and its tokens.
 */
final class AiCall
{
    private const EVENTS = 'Laravel\Ai\Events\\';

    /**
     * Events that begin a call, with the operation for calls without an agent.
     *
     * @var array<string, string|null>
     */
    public const STARTS = [
        self::EVENTS.'PromptingAgent' => null,
        self::EVENTS.'StreamingAgent' => null,
        self::EVENTS.'GeneratingEmbeddings' => 'Embeddings',
        self::EVENTS.'GeneratingImage' => 'Images',
        self::EVENTS.'GeneratingAudio' => 'Audio',
        self::EVENTS.'GeneratingTranscription' => 'Transcription',
        self::EVENTS.'Reranking' => 'Reranking',
        self::EVENTS.'Classifying' => 'Classification',
    ];

    /**
     * Events that end one.
     *
     * @var array<string, string|null>
     */
    public const ENDS = [
        self::EVENTS.'AgentPrompted' => null,
        self::EVENTS.'AgentStreamed' => null,
        self::EVENTS.'EmbeddingsGenerated' => 'Embeddings',
        self::EVENTS.'ImageGenerated' => 'Images',
        self::EVENTS.'AudioGenerated' => 'Audio',
        self::EVENTS.'TranscriptionGenerated' => 'Transcription',
        self::EVENTS.'Reranked' => 'Reranking',
        self::EVENTS.'Classified' => 'Classification',
    ];

    /**
     * Only in laravel/ai 0.11 and later. Before that, a call that never
     * ends is how a failure shows.
     */
    public const FAILED = self::EVENTS.'AgentFailed';

    public const TOOL_STARTS = self::EVENTS.'InvokingTool';

    public const TOOL_ENDS = [self::EVENTS.'ToolInvoked', self::EVENTS.'ToolFailed'];

    /**
     * The agent's class, or the operation for calls without an agent.
     */
    public static function name(object $event): string
    {
        $agent = self::read($event, 'prompt', 'agent');

        if (is_object($agent)) {
            return $agent::class === 'Laravel\Ai\AnonymousAgent' ? 'Anonymous agent' : $agent::class;
        }

        return self::STARTS[$event::class] ?? self::ENDS[$event::class] ?? 'AI';
    }

    /**
     * The provider's driver, e.g. openai or gemini, which says whose model it is.
     */
    public static function provider(object $event): string
    {
        $provider = self::read($event, 'provider') ?? self::read($event, 'prompt', 'provider');

        return match (true) {
            is_object($provider) && method_exists($provider, 'driver') => (string) $provider->driver(),
            is_object($provider) && method_exists($provider, 'name') => (string) $provider->name(),
            default => 'unknown',
        };
    }

    /**
     * The model asked for. Responses may name a dated version of it.
     */
    public static function model(object $event): string
    {
        $model = self::read($event, 'model') ?? self::read($event, 'prompt', 'model') ?? self::read($event, 'response', 'meta', 'model');

        return is_string($model) && $model !== '' ? $model : 'unknown';
    }

    /**
     * Tokens in (all of them, cached included), out, read from the cache
     * and written to it. laravel/ai 1.0 renamed prompt and completion
     * tokens to input and output.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    public static function tokens(object $event): array
    {
        $usage = self::read($event, 'response', 'usage');

        if (! is_object($usage)) {
            return [0, 0, 0, 0];
        }

        $count = fn (string ...$names) => (int) array_sum(array_map(fn (string $name) => is_numeric($usage->{$name} ?? null) ? $usage->{$name} : 0, $names));

        return [
            $count('inputTokens', 'promptTokens'),
            $count('outputTokens', 'completionTokens'),
            $count('cacheReadInputTokens'),
            $count('cacheWriteInputTokens'),
        ];
    }

    public static function id(object $event, string $property = 'invocationId'): ?string
    {
        $id = self::read($event, $property);

        return is_string($id) ? $id : null;
    }

    /**
     * A nested public property, or null when any step is missing.
     */
    private static function read(object $object, string ...$path): mixed
    {
        $value = $object;

        foreach ($path as $property) {
            if (! is_object($value) || ! isset($value->{$property})) {
                return null;
            }

            $value = $value->{$property};
        }

        return $value;
    }
}
