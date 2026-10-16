<?php

namespace MohammedMojaly\Laralyze\Alerts;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Sends one message to one channel.
 */
class Sender
{
    public const TIMEOUT = 5;

    public function __construct(private Repository $config) {}

    /**
     * Null when it went through; otherwise why not, without any secret in it.
     */
    public function send(string $channel, Message $message): ?string
    {
        [$kind, $target] = array_pad(explode(':', $channel, 2), 2, '');

        try {
            match ($kind) {
                'mail' => Notification::route('mail', $target)->notifyNow(new AlertNotification($message)),
                'slack' => Http::timeout(self::TIMEOUT)->post($this->setting('slack'), ['text' => $message->slack()])->throw(),
                'discord' => Http::timeout(self::TIMEOUT)->post($this->setting('discord'), [
                    'content' => $message->discord(),
                    'allowed_mentions' => ['parse' => []],
                ])->throw(),
                'telegram' => Http::timeout(self::TIMEOUT)->post('https://api.telegram.org/bot'.$this->setting('telegram.token').'/sendMessage', [
                    'chat_id' => $target,
                    'text' => $message->telegram(),
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ])->throw(),
                'webhook' => $this->webhook($message),
                default => throw new RuntimeException("Unknown channel [{$channel}]."),
            };

            return null;
        } catch (Throwable $e) {
            return Str::limit($this->redact($e->getMessage()), 300);
        }
    }

    /**
     * A hex HMAC-SHA256 of "{timestamp}.{body}": the receiver recomputes it,
     * and rejects old timestamps so a captured request can't be replayed.
     */
    public static function signature(string $timestamp, string $body, string $secret): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    protected function webhook(Message $message): void
    {
        $body = (string) json_encode($message->payload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $secret = (string) $this->config->get('laralyze.alerts.webhook.secret', '');
        $headers = [];

        if ($secret !== '') {
            $timestamp = (string) now()->getTimestamp();
            $headers = ['X-Laralyze-Timestamp' => $timestamp, 'X-Laralyze-Signature' => self::signature($timestamp, $body, $secret)];
        }

        Http::timeout(self::TIMEOUT)->withHeaders($headers)->withBody($body, 'application/json')->post($this->setting('webhook.url'))->throw();
    }

    protected function setting(string $key): string
    {
        $value = (string) $this->config->get("laralyze.alerts.{$key}", '');

        if ($value === '') {
            throw new RuntimeException('Not set in .env.');
        }

        return $value;
    }

    /**
     * Webhook URLs, the bot token and the secret are passwords.
     */
    protected function redact(string $error): string
    {
        $secrets = array_filter(array_map(fn (string $key) => (string) $this->config->get("laralyze.alerts.{$key}", ''), [
            'slack', 'discord', 'telegram.token', 'webhook.url', 'webhook.secret',
        ]));

        return $secrets === [] ? $error : str_replace($secrets, '[hidden]', $error);
    }
}
