<?php

namespace MohammedMojaly\Laralyze\Assistant\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use MohammedMojaly\Laralyze\Assistant\Files;

/**
 * Finds where text appears in the app's code.
 */
class SearchCode implements Tool
{
    public function __construct(protected Files $files) {}

    public function description(): string
    {
        return 'Find lines of the app\'s code containing some text (not a regex, case-insensitive), e.g. a class, method, relation or table name. Returns up to 40 "path:line: code" matches.';
    }

    public function handle(Request $request): string
    {
        $matches = $this->files->search((string) $request['text']);

        return $matches === [] ? 'No matches.' : implode("\n", $matches);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->description('At least 3 characters.')->required(),
        ];
    }
}
