<?php

namespace MohammedMojaly\Laralyze\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use MohammedMojaly\Laralyze\Assistant\Assistant;
use MohammedMojaly\Laralyze\Assistant\Charts;
use MohammedMojaly\Laralyze\Assistant\Chats;
use MohammedMojaly\Laralyze\Assistant\Files;
use MohammedMojaly\Laralyze\Assistant\Providers;
use MohammedMojaly\Laralyze\Assistant\Subject;
use MohammedMojaly\Laralyze\Contracts\Storage;
use MohammedMojaly\Laralyze\Laralyze;
use MohammedMojaly\Laralyze\Support\AiPrices;
use Throwable;

/**
 * Ask AI: a conversation about one issue or the whole app, in a side panel
 * on every page, or full width on the Assistant page with your other
 * conversations next to it.
 */
class AssistantPanel extends Component
{
    use Concerns\AuthorizesAccess;

    /**
     * Questions to start with, by what the conversation is about: the
     * question and what the assistant looks at to answer it.
     */
    public const SUGGESTIONS = [
        'general' => [
            ['What is slowest in my app today, and why?', 'Routes, queries and their timelines'],
            ['Which exceptions should I fix first?', 'By how often and how many users'],
            ['Are there N+1 queries worth fixing?', 'Findings with the line in your code'],
            ['What did my AI calls cost this week?', 'Agents, models and tokens'],
        ],
        'exception' => [
            ['What caused this, and how do I fix it?', 'The stack trace and your code'],
            ['How can I reproduce it in a test?', 'A failing test to start from'],
            ['Is it happening more often?', 'Occurrences over time'],
        ],
        'n_plus_one' => [
            ['How do I fix this N+1?', 'The relation to eager load, in your code'],
            ['How much time does it cost?', 'The requests and jobs it slows down'],
        ],
        'duplicate_query' => [
            ['How do I stop running this query twice?', 'Where it runs again, in your code'],
        ],
        'query' => [
            ['Why is this query slow, and how do I speed it up?', 'Its timings and where it runs'],
            ['Which index would help?', 'The SQL and your migrations'],
        ],
        'request' => [
            ['Why is this route slow?', 'Its slowest request, step by step'],
            ['What does it spend its time on?', 'Queries, cache and outgoing calls'],
        ],
        'execution' => [
            ['What happened here, and what should I fix?', 'Its timeline, step by step'],
            ['Why did this take so long?', 'Where the time went'],
        ],
    ];

    /**
     * Full width on the Assistant page instead of the side panel.
     */
    #[Locked]
    public bool $page = false;

    public bool $open = false;

    #[Locked]
    public string $kind = 'general';

    #[Locked]
    public string $key = '';

    /**
     * The conversation shown; null until its first question.
     */
    #[Locked]
    public ?string $chat = null;

    public string $question = '';

    #[Locked]
    public bool $thinking = false;

    public ?string $error = null;

    /**
     * Runs before mount and before every action, so a prompt or a tool
     * never runs for someone the gate turns away.
     */
    public function boot(): void
    {
        Gate::authorize('useLaralyzeAssistant');
    }

    public function mount(bool $page = false): void
    {
        [$this->page, $this->open] = [$page, $page];

        // A conversation's own link: /laralyze/assistant?chat=…, yours only.
        if ($page && is_string($id = request()->query('chat'))) {
            $this->show($id);
        }
    }

    #[On('laralyze-ask')]
    public function ask(string $kind = 'general', string $key = ''): void
    {
        $subject = Subject::find($kind, $key, app(Storage::class)) ?? Subject::general();

        [$this->kind, $this->key, $this->open, $this->error, $this->thinking] = [$subject->kind, $subject->key, true, null, false];
        // Nothing is asked until you pick a question or write one.
        $this->chat = app(Chats::class)->latest($this->owner(), $subject);
    }

    /**
     * A new conversation about the whole app.
     */
    public function start(): void
    {
        [$this->kind, $this->key, $this->chat, $this->open, $this->error, $this->thinking] = ['general', '', null, true, null, false];
    }

    /**
     * Show one of your conversations.
     */
    public function show(string $id): void
    {
        $chat = app(Chats::class)->find($this->owner(), $id);

        if ($chat !== null) {
            [$this->kind, $this->key, $this->chat, $this->open, $this->error, $this->thinking] = [$chat['subject']['kind'], $chat['subject']['key'], $id, true, null, false];
        }
    }

    public function send(): void
    {
        $question = trim($this->question);

        if ($question === '' || $this->thinking || $this->provider() === null) {
            return;
        }

        $this->question = '';
        $this->queue(Str::limit($question, 4_000, ''));
    }

    public function suggest(int $index): void
    {
        $this->question = self::SUGGESTIONS[$this->kind][$index][0] ?? '';
        $this->send();
    }

    /**
     * Answer the last question. Runs as its own request so the question
     * shows at once, and streams the answer as it comes.
     */
    public function reply(): void
    {
        if (! $this->thinking) {
            return;
        }

        $this->thinking = false;
        $messages = $this->messages();
        $last = array_pop($messages);
        $provider = $this->provider();

        if ($last === null || $last['role'] !== 'user' || $provider === null) {
            return;
        }

        @set_time_limit(300);

        $subject = $this->subject();
        $model = app(Providers::class)->model($provider);
        $history = array_map(fn (array $message) => ['role' => (string) $message['role'], 'content' => (string) $message['content']], $messages);
        $assistant = new Assistant($subject->context(app(Storage::class), app(Files::class)), $history);

        try {
            // Its own calls are never counted as the app's.
            [$text, $usage] = app(Laralyze::class)->ignore(fn () => config('laralyze.assistant.stream', true)
                ? $this->streamed($assistant, (string) $last['content'], $provider, $model)
                : $this->answered($assistant, (string) $last['content'], $provider, $model));
        } catch (Throwable $e) {
            $this->error = Str::limit($e->getMessage(), 500);

            return;
        }

        $tokens = [(int) ($usage->inputTokens ?? 0), (int) ($usage->outputTokens ?? 0), (int) ($usage->cacheReadInputTokens ?? 0), (int) ($usage->cacheWriteInputTokens ?? 0)];

        $messages[] = $last;
        $messages[] = [
            'role' => 'assistant',
            'content' => $text,
            'in' => $tokens[0],
            'out' => $tokens[1],
            'cost' => app(AiPrices::class)->cost(app(Providers::class)->driver($provider), $model, $tokens),
            'model' => $model,
            'at' => time(),
        ];

        $this->chat = app(Chats::class)->save($this->owner(), $this->chat, $subject, $messages);
    }

    public function retry(): void
    {
        $this->error = null;
        $this->thinking = true;
        $this->js('$wire.reply()');
    }

    /**
     * Start a new conversation about the same thing. The old one stays in
     * your list.
     */
    public function newChat(): void
    {
        [$this->chat, $this->thinking, $this->error] = [null, false, null];
    }

    public function forget(string $id): void
    {
        app(Chats::class)->forget($this->owner(), $id);

        if ($this->chat === $id) {
            $this->newChat();
        }
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function render(): View
    {
        $provider = $this->provider();
        $messages = $this->messages();

        return view($this->page ? 'laralyze::assistant.page' : 'laralyze::assistant.panel', [
            'subject' => $this->subject(),
            'messages' => array_map($this->present(...), $messages),
            'cost' => array_sum(array_map(fn (array $message) => (float) ($message['cost'] ?? 0), $messages)),
            'active' => $provider,
            'driver' => $provider === null ? null : app(Providers::class)->driver($provider),
            'current' => $provider === null ? null : app(Providers::class)->model($provider),
            'chats' => $this->page ? app(Chats::class)->list($this->owner()) : [],
            'suggestions' => self::SUGGESTIONS[$this->kind] ?? [],
        ]);
    }

    /**
     * @return array{0: string, 1: object|null}
     */
    protected function answered(Assistant $assistant, string $question, string $provider, string $model): array
    {
        $response = $assistant->prompt($question, provider: $provider, model: $model, timeout: 240);

        return [$response->text, $response->usage];
    }

    /**
     * @return array{0: string, 1: object|null}
     */
    protected function streamed(Assistant $assistant, string $question, string $provider, string $model): array
    {
        $response = $assistant->stream($question, provider: $provider, model: $model, timeout: 240);
        $text = '';

        foreach ($response as $event) {
            if ($event instanceof TextDelta) {
                $text .= $event->delta;
                [$readable, $block] = $this->readable($text);

                $this->stream(to: 'answer', content: e($readable), replace: true);

                if ($block !== null) {
                    $this->stream(to: 'status', content: $block === 'chart' ? 'Drawing a chart…' : 'Writing a prompt for your coding agent…', replace: true);
                }
            } elseif ($event instanceof ToolCall) {
                $this->stream(to: 'status', content: e($this->doing($event->toolCall->name, $event->toolCall->arguments)), replace: true);
            }
        }

        return [(string) $response->text, $response->usage];
    }

    /**
     * The answer so far without its chart and prompt blocks, which only
     * make sense once complete, and the kind of block being written, if any.
     *
     * @return array{0: string, 1: string|null}
     */
    protected function readable(string $text): array
    {
        $text = (string) preg_replace('/```(chart|prompt)[^\n]*\n.*?```\n?/s', '', $text);

        if (preg_match('/```(chart|prompt)\b.*$/s', $text, $open, PREG_OFFSET_CAPTURE)) {
            return [substr($text, 0, (int) $open[0][1]), $open[1][0]];
        }

        return [$text, null];
    }

    /**
     * What a tool call is doing, for the status line while it runs.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function doing(string $tool, array $arguments): string
    {
        return match ($tool) {
            'ReadFile' => 'Reading '.Str::limit((string) ($arguments['path'] ?? 'a file'), 80).'…',
            'SearchCode' => 'Searching the code for “'.Str::limit((string) ($arguments['text'] ?? ''), 60).'”…',
            'LaralyzeData' => 'Reading Laralyze’s '.str_replace('_', ' ', (string) ($arguments['topic'] ?? 'data')).'…',
            default => 'Working…',
        };
    }

    /**
     * A message ready to show: Markdown as HTML in blocks, with charts drawn
     * from Laralyze's data and the prompt for a coding agent taken out to
     * its own box.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    protected function present(array $message): array
    {
        $content = (string) $message['content'];

        if ($message['role'] !== 'assistant') {
            return [...$message, 'blocks' => [], 'prompt' => null, 'text' => $content];
        }

        $prompt = null;

        if (preg_match('/```prompt[^\n]*\n(.*?)(```|$)/s', $content, $match)) {
            $prompt = trim($match[1]);
            $content = trim(str_replace($match[0], '', $content));
        }

        $blocks = [];

        foreach (preg_split('/(```chart[^\n]*\n.*?(?:```|$))/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (preg_match('/^```chart[^\n]*\n(.*?)(?:```)?$/s', $part, $chart)) {
                try {
                    $made = app(Charts::class)->make($chart[1]);
                } catch (Throwable) {
                    $made = [];
                }

                foreach ($made as $drawn) {
                    $blocks[] = ['chart' => $drawn];
                }

                continue;
            }

            if (trim($part) !== '') {
                $blocks[] = ['html' => $this->html($part)];
            }
        }

        return [...$message, 'blocks' => $blocks, 'prompt' => $prompt, 'text' => trim((string) preg_replace('/```chart[^\n]*\n.*?(```|$)/s', '', $content))];
    }

    /**
     * Markdown as safe HTML. Each block takes the direction of its own
     * text, so Arabic or Hebrew answers read right to left.
     */
    protected function html(string $markdown): string
    {
        $html = Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);

        return (string) preg_replace('/<(p|li|ul|ol|h[1-6]|blockquote|td|th)>/', '<$1 dir="auto">', $html);
    }

    protected function queue(string $question): void
    {
        $messages = $this->messages();
        $messages[] = ['role' => 'user', 'content' => $question, 'at' => time()];

        $this->chat = app(Chats::class)->save($this->owner(), $this->chat, $this->subject(), $messages);

        [$this->thinking, $this->error] = [true, null];
        $this->js('$wire.reply()');
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function messages(): array
    {
        return $this->chat === null ? [] : (app(Chats::class)->find($this->owner(), $this->chat)['messages'] ?? []);
    }

    protected function subject(): Subject
    {
        return Subject::find($this->kind, $this->key, app(Storage::class)) ?? Subject::general();
    }

    protected function provider(): ?string
    {
        return app(Providers::class)->provider();
    }

    /**
     * Whose conversation it is: each person signed in to the dashboard has their own.
     */
    protected function owner(): string
    {
        return (string) (auth()->id() ?? 'guest');
    }

    /**
     * Whether the person looking may use it: laravel/ai is there and the
     * useLaralyzeAssistant gate lets them.
     */
    public static function allowed(): bool
    {
        return self::enabled() && Gate::allows('useLaralyzeAssistant');
    }

    public static function enabled(): bool
    {
        // laravel/ai 1.0 and later, checked by name: loading Assistant
        // without laravel/ai would be fatal.
        return class_exists('Laravel\Ai\Responses\Data\TextUsage')
            && interface_exists('Laravel\Ai\Contracts\Conversational')
            && (bool) config('laralyze.assistant.enabled', true);
    }
}
