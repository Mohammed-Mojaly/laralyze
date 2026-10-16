<?php

namespace MohammedMojaly\Laralyze\Alerts;

use Illuminate\Contracts\Config\Repository;

/**
 * Where alerts can go: the channels set in .env. Each mail address and
 * each Telegram chat is a channel of its own.
 */
final class Channels
{
    public function __construct(private Repository $config) {}

    /**
     * @return array<string, string> Id => label.
     */
    public function configured(): array
    {
        $channels = [];

        foreach ($this->list('laralyze.alerts.mail') as $address) {
            $channels["mail:{$address}"] = "Mail: {$address}";
        }

        if ((string) $this->config->get('laralyze.alerts.slack', '') !== '') {
            $channels['slack'] = 'Slack';
        }

        if ((string) $this->config->get('laralyze.alerts.discord', '') !== '') {
            $channels['discord'] = 'Discord';
        }

        if ((string) $this->config->get('laralyze.alerts.telegram.token', '') !== '') {
            foreach ($this->list('laralyze.alerts.telegram.chats') as $chat) {
                $channels["telegram:{$chat}"] = "Telegram: {$chat}";
            }
        }

        if ((string) $this->config->get('laralyze.alerts.webhook.url', '') !== '') {
            $channels['webhook'] = 'Webhook';
        }

        return $channels;
    }

    /**
     * A rule's channels as they are now: "all" is every channel set in .env.
     * A channel taken out of .env stays, and fails when it's sent to.
     *
     * @param  list<string>  $chosen
     * @return list<string>
     */
    public function resolve(array $chosen): array
    {
        return in_array(Rule::ALL_CHANNELS, $chosen, true) ? array_keys($this->configured()) : $chosen;
    }

    /**
     * @return list<string>
     */
    private function list(string $key): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', (string) $this->config->get($key, '')))));
    }
}
