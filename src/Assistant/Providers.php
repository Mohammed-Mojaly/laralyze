<?php

namespace MohammedMojaly\Laralyze\Assistant;

use Illuminate\Contracts\Config\Repository;
use Laravel\Ai\AiManager;
use Throwable;

/**
 * The AI providers the app has set up in config/ai.php that can chat, and
 * the one the assistant uses.
 */
class Providers
{
    /**
     * @var array<string, array{driver: string, model: string}>|null
     */
    protected ?array $available = null;

    public function __construct(protected Repository $config) {}

    /**
     * Provider name => its driver and default model. Only providers with
     * an API key, or Ollama when it's the app's default.
     *
     * @return array<string, array{driver: string, model: string}>
     */
    public function available(): array
    {
        if ($this->available !== null) {
            return $this->available;
        }

        $this->available = [];

        foreach ((array) $this->config->get('ai.providers', []) as $name => $provider) {
            $driver = (string) ($provider['driver'] ?? $name);

            // Ollama needs no key, but runs only where the app uses it.
            if (blank($provider['key'] ?? null) && ($driver !== 'ollama' || $this->config->get('ai.default') !== $name)) {
                continue;
            }

            // Providers that can't chat, like embeddings-only ones, throw here.
            try {
                $instance = app(AiManager::class)->textProvider((string) $name);
            } catch (Throwable) {
                continue;
            }

            try {
                $model = (string) $instance->defaultTextModel();
            } catch (Throwable) {
                $model = '';
            }

            $this->available[(string) $name] = ['driver' => $driver, 'model' => $model];
        }

        return $this->available;
    }

    /**
     * The provider to use: Laralyze's config, then the app's default AI
     * provider, then the first one with a key.
     */
    public function provider(): ?string
    {
        $available = $this->available();

        foreach ([$this->config->get('laralyze.assistant.provider'), $this->config->get('ai.default')] as $name) {
            if (is_string($name) && isset($available[$name])) {
                return $name;
            }
        }

        return array_key_first($available);
    }

    /**
     * The model to use: Laralyze's config, else the provider's default.
     */
    public function model(string $provider): string
    {
        $configured = $this->config->get('laralyze.assistant.model');

        return is_string($configured) && $configured !== '' ? $configured : ($this->available()[$provider]['model'] ?? '');
    }

    public function driver(string $provider): string
    {
        return $this->available()[$provider]['driver'] ?? $provider;
    }
}
