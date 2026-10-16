<?php

namespace MohammedMojaly\Laralyze\Alerts;

/**
 * Sends what's pending, oldest first. A failed channel is tried again on
 * the next looks, three times in all. A look stops sending after about 30
 * seconds so it never holds up the scheduler; what's left goes out on the
 * next look and doesn't count as an attempt.
 */
final class Deliveries
{
    public const ATTEMPTS = 3;

    public const BUDGET = 30.0;

    public function __construct(private History $history, private Sender $sender, private Channels $channels) {}

    public function send(float $budget = self::BUDGET): void
    {
        $started = microtime(true);
        $configured = $this->channels->configured();

        foreach ($this->history->pending() as $entry) {
            if (! $this->deliver($entry, $configured, $started, $budget)) {
                return;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, string>  $configured
     * @return bool False once the budget is spent.
     */
    public function deliver(array $entry, array $configured, float $started, float $budget): bool
    {
        $message = Message::fromEntry($entry);
        $changed = false;
        $within = true;

        foreach ((array) ($entry['deliveries'] ?? []) as $channel => $delivery) {
            if (($delivery['status'] ?? null) !== History::PENDING) {
                continue;
            }

            if (microtime(true) - $started >= $budget) {
                $within = false;

                break;
            }

            $set = isset($configured[$channel]);
            $error = $set ? $this->sender->send((string) $channel, $message) : 'Not set in .env.';
            $attempts = (int) ($delivery['attempts'] ?? 0) + 1;

            $entry['deliveries'][$channel] = [
                'status' => match (true) {
                    $error === null => History::SENT,
                    ! $set || $attempts >= self::ATTEMPTS => History::FAILED,
                    default => History::PENDING,
                },
                'attempts' => $attempts,
                'error' => $error,
                'at' => now()->getTimestamp(),
            ];
            $changed = true;
        }

        if ($changed) {
            $this->history->save($entry);
        }

        return $within;
    }
}
