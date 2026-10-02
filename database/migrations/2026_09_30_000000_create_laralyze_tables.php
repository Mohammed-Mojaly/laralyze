<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('laralyze.storage.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->create('laralyze_aggregates', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('bucket');
            $table->integer('period');
            $table->string('type', 64);
            $table->string('aggregate', 8);
            $table->text('key');
            $table->char('key_hash', 32);
            $table->decimal('value', 20, 4);

            $table->unique(['bucket', 'period', 'type', 'aggregate', 'key_hash']);
            $table->index(['period', 'type', 'bucket']);
            $table->index(['period', 'bucket']);
        });

        $schema->create('laralyze_values', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('timestamp');
            $table->string('type', 64);
            $table->text('key');
            $table->char('key_hash', 32);
            $table->longText('value');

            $table->unique(['type', 'key_hash']);
        });

        // Single requests, jobs and commands, with what happened inside them.
        $schema->create('laralyze_executions', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 26)->unique();
            $table->char('trace', 26)->index();
            $table->string('type', 16);
            $table->text('name');
            $table->char('name_hash', 32);
            $table->string('status', 16);
            $table->boolean('failed');
            $table->decimal('duration', 12, 2);
            $table->string('user_id', 64)->nullable();
            $table->string('server', 128);
            $table->bigInteger('started_at');
            // ",<hash>,<hash>," of the exceptions it reported, for the exception page.
            $table->text('exceptions');
            $table->text('counts');
            $table->longText('events');

            $table->index(['type', 'name_hash', 'started_at']);
            $table->index(['user_id', 'started_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->dropIfExists('laralyze_aggregates');
        $schema->dropIfExists('laralyze_values');
        $schema->dropIfExists('laralyze_executions');
    }
};
