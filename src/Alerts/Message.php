<?php

namespace MohammedMojaly\Laralyze\Alerts;

use Illuminate\Support\Str;
use MohammedMojaly\Laralyze\Alerts\Metrics\Metric;

/**
 * One alert as it's sent: the title, the items, and where to look, escaped
 * for each channel.
 */
final class Message
{
    /**
     * @param  list<array{item: string, value: string}>  $items
     */
    public function __construct(
        public readonly string $event,
        public readonly string $title,
        public readonly array $items = [],
        public readonly int $more = 0,
        public readonly string $text = '',
        public readonly string $note = '',
        public readonly string $url = '',
        public readonly string $rule = '',
        public readonly string $ruleId = '',
        public readonly int $at = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $entry  A history entry.
     */
    public static function fromEntry(array $entry): self
    {
        return new self(
            event: (string) ($entry['event'] ?? 'fired'),
            title: (string) ($entry['title'] ?? ''),
            items: array_values(array_map(fn (mixed $item) => ['item' => (string) ($item['item'] ?? ''), 'value' => (string) ($item['value'] ?? '')], (array) ($entry['items'] ?? []))),
            more: (int) ($entry['more'] ?? 0),
            text: (string) ($entry['text'] ?? ''),
            note: (string) ($entry['note'] ?? ''),
            url: (string) ($entry['url'] ?? ''),
            rule: (string) ($entry['rule_name'] ?? ''),
            ruleId: (string) ($entry['rule'] ?? ''),
            at: (int) ($entry['at'] ?? 0),
        );
    }

    /**
     * The rule's own title with its variables, or "{rule}: {item} {value} ({operator} {threshold})".
     */
    public static function title(Rule $rule, Metric $metric, string $item, string $value): string
    {
        $threshold = $rule->threshold === null ? '' : $metric->format($rule->threshold);
        $subject = trim($item.' '.$value);

        $title = match (true) {
            $rule->title !== '' => strtr($rule->title, ['{rule}' => $rule->name, '{item}' => $item, '{value}' => $value, '{threshold}' => $threshold]),
            $subject === '' => $rule->name,
            $metric->kind() === Metric::EVENT || $threshold === '' => "{$rule->name}: {$subject}",
            default => "{$rule->name}: {$subject} ({$rule->operator} {$threshold})",
        };

        return mb_substr($title, 0, 150);
    }

    public function heading(): string
    {
        return match ($this->event) {
            'recovered' => 'Back to normal: ',
            'reminder' => 'Still firing: ',
            'test' => 'Test: ',
            default => '',
        }.$this->title;
    }

    /**
     * The lines under the title: the items when there are several, what
     * happened, and the rule's note.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [];

        if (count($this->items) > 1 || $this->more > 0) {
            foreach ($this->items as $item) {
                $lines[] = '• '.$item['item'].($item['value'] === '' ? '' : ': '.$item['value']);
            }

            if ($this->more > 0) {
                $lines[] = "and {$this->more} more";
            }
        }

        if ($this->text !== '') {
            $lines[] = $this->text;
        }

        if ($this->note !== '') {
            $lines[] = $this->note;
        }

        return $lines;
    }

    public function plain(): string
    {
        return implode("\n", [$this->heading(), ...$this->lines(), ...($this->url === '' ? [] : [$this->url])]);
    }

    /**
     * Slack's mrkdwn reads only &, < and >.
     */
    public function slack(): string
    {
        $escape = fn (string $text) => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);

        return implode("\n", [
            '*'.$escape($this->heading()).'*',
            ...array_map($escape, $this->lines()),
            ...($this->url === '' ? [] : ['<'.$this->url.'|Open in Laralyze>']),
        ]);
    }

    /**
     * Discord markdown, within its 2,000 characters. Mentions are turned
     * off where it's sent, so "@everyone" in a message pings no one.
     */
    public function discord(): string
    {
        $escape = fn (string $text) => (string) preg_replace('/^([>#-])/m', '\\\\$1', (string) preg_replace('/([\\\\*_~`|\[\]])/', '\\\\$1', $text));

        return Str::limit(implode("\n", [
            '**'.$escape($this->heading()).'**',
            ...array_map($escape, $this->lines()),
            ...($this->url === '' ? [] : [$this->url]),
        ]), 1_900);
    }

    /**
     * Telegram's HTML, within its 4,096 characters. Telegram counts the text
     * as read, so the lines are cut before escaping, and the link stays.
     */
    public function telegram(): string
    {
        $escape = fn (string $text) => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE);
        $lines = Str::limit(implode("\n", $this->lines()), 3_800);

        return implode("\n", [
            '<b>'.$escape($this->heading()).'</b>',
            ...($lines === '' ? [] : [$escape($lines)]),
            ...($this->url === '' ? [] : ['<a href="'.$escape($this->url).'">Open in Laralyze</a>']),
        ]);
    }

    /**
     * What the webhook gets, as JSON.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'event' => $this->event,
            'rule' => ['id' => $this->ruleId, 'name' => $this->rule],
            'title' => $this->title,
            'items' => $this->items,
            'more' => $this->more,
            'text' => $this->text,
            'note' => $this->note,
            'url' => $this->url,
            'at' => $this->at,
        ];
    }
}
