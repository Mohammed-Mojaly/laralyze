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
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->dropIfExists('laralyze_aggregates');
        $schema->dropIfExists('laralyze_values');
    }
};
