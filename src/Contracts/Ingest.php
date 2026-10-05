<?php

namespace MohammedMojaly\Laralyze\Contracts;

/**
 * How a flush reaches storage: straight in, or queued for one writer.
 */
interface Ingest
{
    /**
     * @param  list<array{bucket: int, period: int, type: string, aggregate: string, key: string, value: float}>  $rows
     * @param  list<array{timestamp: int, type: string, key: string, value: string}>  $values
     * @param  list<array<string, mixed>>  $executions
     */
    public function write(array $rows, array $values, array $executions): void;

    /**
     * Merge what's waiting into storage, for up to the given seconds.
     * Returns how many batches were merged.
     */
    public function digest(int $seconds = 50): int;

    /**
     * When the last digest ran, null when it never has.
     */
    public function digestedAt(): ?int;

    /**
     * Remove what waited longer than the retention period.
     */
    public function trim(int $retentionDays): void;
}
