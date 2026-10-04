<?php

namespace MohammedMojaly\Laralyze\Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class SearchBooks implements Tool
{
    public function description(): string
    {
        return 'Search the catalogue.';
    }

    public function handle(Request $request): string
    {
        return 'Dune, 1965';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
