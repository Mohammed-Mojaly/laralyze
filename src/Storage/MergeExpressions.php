<?php

namespace Laralyze\Storage;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;

/**
 * Builds the "combine with what's already there" part of an upsert.
 * Each database names the incoming row differently, so this is the one
 * place that knows about MySQL, Postgres, SQLite and SQL Server.
 */
final class MergeExpressions
{
    public function __construct(private Connection $connection, private string $table) {}

    /**
     * @return Expression<float|int|literal-string>
     */
    public function add(string $column): Expression
    {
        return new Expression("{$this->existing($column)} + {$this->incoming($column)}");
    }

    /**
     * @return Expression<float|int|literal-string>
     */
    public function min(string $column): Expression
    {
        return $this->pick($column, '<');
    }

    /**
     * @return Expression<float|int|literal-string>
     */
    public function max(string $column): Expression
    {
        return $this->pick($column, '>');
    }

    /**
     * CASE works everywhere, unlike least()/greatest() or SQLite's min()/max().
     *
     * @return Expression<float|int|literal-string>
     */
    private function pick(string $column, string $operator): Expression
    {
        $existing = $this->existing($column);
        $incoming = $this->incoming($column);

        return new Expression("case when {$incoming} {$operator} {$existing} then {$incoming} else {$existing} end");
    }

    private function existing(string $column): string
    {
        return $this->connection->getQueryGrammar()->wrap($this->table.'.'.$column);
    }

    private function incoming(string $column): string
    {
        $grammar = $this->connection->getQueryGrammar();
        $column = $grammar->wrap($column);

        return match ($this->connection->getDriverName()) {
            'mysql', 'mariadb' => $this->connection->getConfig('use_upsert_alias')
                ? $grammar->wrap('laravel_upsert_alias').'.'.$column
                : "values({$column})",
            'sqlsrv' => $grammar->wrap('laravel_source').'.'.$column,
            default => $grammar->wrap('excluded').'.'.$column,
        };
    }
}
