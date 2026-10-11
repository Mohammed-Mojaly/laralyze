<?php

namespace MohammedMojaly\Laralyze\Cards;

use MohammedMojaly\Laralyze\Livewire\Card;
use MohammedMojaly\Laralyze\Livewire\Concerns\PicksConnection;
use MohammedMojaly\Laralyze\Recorders;

/**
 * A card of the Database page, about one watched connection.
 */
abstract class DatabaseCard extends Card
{
    use PicksConnection;

    /**
     * The connection shown: the one picked, or the first watched.
     */
    public function database(): string
    {
        if ($this->connection !== '') {
            return $this->connection;
        }

        $recorder = $this->recorder(Recorders\Database::class);

        return $recorder instanceof Recorders\Database ? ($recorder->connections()[0] ?? '') : (string) config('database.default');
    }

    /**
     * The latest look at it: 'database' every minute, 'database_size' every hour.
     *
     * @return array<string, mixed>|null
     */
    protected function latest(string $type): ?array
    {
        $value = json_decode((string) ($this->values($type, [$this->database()])->first()->value ?? ''), true);

        return is_array($value) ? $value : null;
    }
}
