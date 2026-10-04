<?php

namespace MohammedMojaly\Laralyze\Support;

use Illuminate\Contracts\Config\Repository;
use MohammedMojaly\Laralyze\Laralyze;
use MohammedMojaly\Laralyze\Recorders\Ai;
use MohammedMojaly\Laralyze\Storage\DatabaseStorage;

/**
 * What AI models cost, in USD per million tokens, for estimating what
 * each call cost. Prices come from OpenRouter's public model list, which
 * passes on what each provider charges. Laralyze ships a copy; run
 * `php artisan laralyze:ai-prices` for today's, and set your own in
 * config for models it doesn't know or that you pay differently for.
 *
 * A price is [input, output, cache read, cache write].
 *
 * @phpstan-type Price array{0: float, 1: float, 2: float|null, 3: float|null}
 */
class AiPrices
{
    public const SOURCES = [
        'https://openrouter.ai/api/v1/models',
        'https://openrouter.ai/api/v1/embeddings/models',
    ];

    /**
     * Where `laralyze:ai-prices` keeps the prices it fetched.
     */
    public const STORED = ['ai_prices', 'openrouter'];

    /**
     * laravel/ai drivers whose models OpenRouter lists under another name.
     */
    protected const VENDORS = ['gemini' => 'google', 'xai' => 'x-ai', 'mistral' => 'mistralai', 'azure' => 'openai'];

    /**
     * Models that run on your own machines.
     */
    protected const FREE = ['ollama'];

    /**
     * @var array<string, Price>|null
     */
    protected ?array $table = null;

    /**
     * The same prices by model name alone, for providers that host other vendors' models.
     *
     * @var array<string, Price|false>
     */
    protected array $byName = [];

    protected int $loadedAt = 0;

    public function __construct(protected Repository $config, protected DatabaseStorage $storage, protected Laralyze $laralyze) {}

    /**
     * USD per million tokens, or null when the model is unknown.
     *
     * @return Price|null
     */
    public function for(string $provider, string $model): ?array
    {
        $own = $this->config->get('laralyze.recorders.'.Ai::class.'.prices', []);

        foreach (["{$provider}/{$model}", $model] as $name) {
            if (is_array($own[$name] ?? null)) {
                $price = $own[$name];

                return [(float) ($price['input'] ?? 0), (float) ($price['output'] ?? 0), isset($price['cache_read']) ? (float) $price['cache_read'] : null, isset($price['cache_write']) ? (float) $price['cache_write'] : null];
            }
        }

        if (in_array($provider, self::FREE, true)) {
            return [0.0, 0.0, null, null];
        }

        $table = $this->table();
        $vendor = self::VENDORS[$provider] ?? $provider;

        // OpenRouter's own names are vendor/model already.
        foreach (array_unique([$model, "{$vendor}/{$model}"]) as $name) {
            $price = $table[self::normalize($name)] ?? null;

            if ($price !== null) {
                return $price;
            }
        }

        // Groq, Bedrock and other hosts: the model name alone, when only one vendor has it.
        $price = $this->byName[self::normalize(self::after($model))] ?? null;

        return is_array($price) ? $price : null;
    }

    /**
     * What a call cost in USD, or null when the model's price is unknown.
     *
     * @param  array{0: int, 1: int, 2: int, 3: int}  $tokens  in (cached included), out, cache read, cache write
     */
    public function cost(string $provider, string $model, array $tokens): ?float
    {
        $price = $this->for($provider, $model);

        if ($price === null) {
            return null;
        }

        [$in, $out, $read, $write] = $tokens;
        [$inPrice, $outPrice, $readPrice, $writePrice] = $price;

        return (max(0, $in - $read - $write) * $inPrice + $read * ($readPrice ?? $inPrice) + $write * ($writePrice ?? $inPrice) + $out * $outPrice) / 1_000_000;
    }

    /**
     * The shipped prices with the ones fetched since on top. Kept for an
     * hour, so long-running workers pick up new ones.
     *
     * @return array<string, Price>
     */
    protected function table(): array
    {
        if ($this->table !== null && $this->loadedAt > time() - 3_600) {
            return $this->table;
        }

        $shipped = require __DIR__.'/../../resources/ai-prices.php';

        $stored = $this->laralyze->rescue(fn () => $this->laralyze->ignore(
            fn () => $this->storage->values(self::STORED[0], [self::STORED[1]])->first()?->value,
        ));

        $fetched = is_string($stored) ? json_decode($stored, true) : null;

        $this->loadedAt = time();
        $this->table = [];
        $this->byName = [];

        foreach ([...$shipped, ...(is_array($fetched) ? $fetched : [])] as $name => $price) {
            if (! is_array($price) || count($price) < 2) {
                continue;
            }

            $key = self::normalize((string) $name);
            $price = [(float) $price[0], (float) $price[1], isset($price[2]) ? (float) $price[2] : null, isset($price[3]) ? (float) $price[3] : null];
            $this->table[$key] = $price;

            $short = self::after($key);
            $this->byName[$short] = isset($this->byName[$short]) && $this->byName[$short] !== $price ? false : $price;
        }

        return $this->table;
    }

    /**
     * Turn OpenRouter's lists into name => [input, output, cache read,
     * cache write] per million tokens. Batch, free and other variants
     * are left out.
     *
     * @param  array<array-key, mixed>  ...$lists  Decoded responses of SOURCES.
     * @return array<string, Price>
     */
    public static function fromOpenRouter(array ...$lists): array
    {
        $prices = [];
        $perMillion = fn (mixed $perToken) => is_numeric($perToken) && (float) $perToken >= 0 ? round((float) $perToken * 1_000_000, 6) : null;

        foreach ($lists as $list) {
            foreach ((array) ($list['data'] ?? []) as $model) {
                $id = is_array($model) ? (string) ($model['id'] ?? '') : '';
                $pricing = (array) ($model['pricing'] ?? []);

                if ($id === '' || str_contains($id, ':') || str_starts_with($id, '~') || $perMillion($pricing['prompt'] ?? null) === null) {
                    continue;
                }

                $prices[$id] = [
                    (float) $perMillion($pricing['prompt']),
                    (float) ($perMillion($pricing['completion'] ?? 0) ?? 0),
                    $perMillion($pricing['input_cache_read'] ?? null),
                    $perMillion($pricing['input_cache_write'] ?? null),
                ];
            }
        }

        ksort($prices);

        return $prices;
    }

    /**
     * One spelling for one model: Anthropic's API says claude-haiku-4-5-20251001
     * where OpenRouter says claude-haiku-4.5, OpenAI adds dates too.
     */
    public static function normalize(string $name): string
    {
        $name = str_replace(['.', '_'], '-', strtolower(trim($name)));

        return (string) preg_replace('/-(\d{4}-\d{2}-\d{2}|\d{8}|\d{4}|latest)$/', '', $name);
    }

    protected static function after(string $name): string
    {
        $slash = strrpos($name, '/');

        return $slash === false ? $name : substr($name, $slash + 1);
    }
}
