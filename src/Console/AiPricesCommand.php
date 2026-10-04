<?php

namespace MohammedMojaly\Laralyze\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use MohammedMojaly\Laralyze\Laralyze;
use MohammedMojaly\Laralyze\Storage\DatabaseStorage;
use MohammedMojaly\Laralyze\Support\AiPrices;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'laralyze:ai-prices')]
class AiPricesCommand extends Command
{
    protected $signature = 'laralyze:ai-prices';

    protected $description = "Fetch today's AI model prices from OpenRouter for Laralyze's cost estimates";

    public function handle(DatabaseStorage $storage, Laralyze $laralyze): int
    {
        try {
            $lists = array_map(fn (string $url) => Http::timeout(30)->acceptJson()->get($url)->throw()->json(), AiPrices::SOURCES);
        } catch (Throwable $e) {
            $this->components->error("Couldn't fetch prices: {$e->getMessage()}");

            return self::FAILURE;
        }

        $prices = AiPrices::fromOpenRouter(...array_map(fn (mixed $list) => (array) $list, $lists));

        if ($prices === []) {
            $this->components->error('OpenRouter returned no prices. Nothing was changed.');

            return self::FAILURE;
        }

        $laralyze->ignore(fn () => $storage->put(AiPrices::STORED[0], AiPrices::STORED[1], (string) json_encode($prices, JSON_UNESCAPED_SLASHES)));

        $this->components->info('Saved prices for '.count($prices).' models. Running workers pick them up within an hour.');

        return self::SUCCESS;
    }
}
