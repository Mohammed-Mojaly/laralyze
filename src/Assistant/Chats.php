<?php

namespace MohammedMojaly\Laralyze\Assistant;

use Illuminate\Support\Str;
use MohammedMojaly\Laralyze\Contracts\Storage;

/**
 * Conversations with the assistant, kept in Laralyze's own tables for a
 * week. Each person has their own; asking about the same exception again
 * picks up the latest conversation about it.
 *
 * A message has a role (user or assistant), its content and when it was
 * sent; an answer also has its model, tokens and cost.
 *
 * @phpstan-type Chat array{owner: string, subject: array{kind: string, key: string, label: string}, messages: list<array<string, mixed>>}
 */
class Chats
{
    public const TYPE = 'assistant_chat';

    public const DAYS = 7;

    public function __construct(protected Storage $storage) {}

    /**
     * A conversation, when it exists and is this person's.
     *
     * @return Chat|null
     */
    public function find(string $owner, string $id): ?array
    {
        $chat = json_decode((string) ($this->storage->values(self::TYPE, [$id])->first()->value ?? ''), true);

        if (! is_array($chat) || ($chat['owner'] ?? null) !== $owner || ! is_array($chat['messages'] ?? null)) {
            return null;
        }

        $subject = (array) ($chat['subject'] ?? []);

        return [
            'owner' => $owner,
            'subject' => ['kind' => (string) ($subject['kind'] ?? 'general'), 'key' => (string) ($subject['key'] ?? ''), 'label' => (string) ($subject['label'] ?? 'Your app')],
            'messages' => array_values($chat['messages']),
        ];
    }

    /**
     * The id of someone's latest conversation about a subject.
     */
    public function latest(string $owner, Subject $subject): ?string
    {
        foreach ($this->list($owner) as $chat) {
            if ($chat['kind'] === $subject->kind && $chat['key'] === $subject->key) {
                return $chat['id'];
            }
        }

        return null;
    }

    /**
     * Save a conversation, giving it an id the first time.
     *
     * @param  list<array<string, mixed>>  $messages
     */
    public function save(string $owner, ?string $id, Subject $subject, array $messages): string
    {
        $id ??= (string) Str::ulid();

        $this->storage->put(self::TYPE, $id, (string) json_encode(
            ['owner' => $owner, 'subject' => $subject->toArray(), 'messages' => $messages],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        ));

        return $id;
    }

    public function forget(string $owner, string $id): void
    {
        if ($this->find($owner, $id) !== null) {
            $this->storage->forget(self::TYPE, $id);
        }
    }

    /**
     * Someone's conversations, the latest first, titled by their first question.
     *
     * @return list<array{id: string, kind: string, key: string, label: string, title: string, at: int, messages: int}>
     */
    public function list(string $owner): array
    {
        $chats = [];

        foreach ($this->storage->values(self::TYPE)->sortByDesc('timestamp') as $row) {
            $chat = json_decode((string) $row->value, true);

            if (! is_array($chat) || ($chat['owner'] ?? null) !== $owner || ($chat['messages'] ?? []) === []) {
                continue;
            }

            $chats[] = [
                'id' => (string) $row->key,
                'kind' => (string) ($chat['subject']['kind'] ?? 'general'),
                'key' => (string) ($chat['subject']['key'] ?? ''),
                'label' => (string) ($chat['subject']['label'] ?? 'Your app'),
                'title' => Str::limit((string) ($chat['messages'][0]['content'] ?? ''), 80),
                'at' => (int) $row->timestamp,
                'messages' => count((array) $chat['messages']),
            ];
        }

        return $chats;
    }
}
