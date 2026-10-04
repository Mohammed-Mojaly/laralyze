<?php

namespace MohammedMojaly\Laralyze\Assistant\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use MohammedMojaly\Laralyze\Assistant\Files;

/**
 * Reads a file of the app, in the folders Laralyze may read.
 */
class ReadFile implements Tool
{
    public function __construct(protected Files $files) {}

    public function description(): string
    {
        return 'Read a file of the app, with line numbers, from the project root, e.g. "app/Models/User.php". Up to '.Files::MAX_LINES.' lines at a time; pass from and to for other lines. Secrets are masked; .env and key files are never readable.';
    }

    public function handle(Request $request): string
    {
        return $this->files->read((string) $request['path'], isset($request['from']) ? (int) $request['from'] : null, isset($request['to']) ? (int) $request['to'] : null);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('Path from the project root.')->required(),
            'from' => $schema->integer()->description('First line, from 1.'),
            'to' => $schema->integer()->description('Last line.'),
        ];
    }
}
