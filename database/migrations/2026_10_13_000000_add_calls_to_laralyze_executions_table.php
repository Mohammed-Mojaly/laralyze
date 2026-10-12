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

        // No table yet (it's made with the column), or an earlier copy of this
        // migration, which the app may have pruned, added it.
        if (! $schema->hasTable('laralyze_executions') || $schema->hasColumn('laralyze_executions', 'calls')) {
            return;
        }

        // ",<hash>,<hash>," of the queries and outgoing requests it made, for their pages.
        $schema->table('laralyze_executions', function (Blueprint $table) {
            $table->text('calls')->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        if ($schema->hasTable('laralyze_executions') && $schema->hasColumn('laralyze_executions', 'calls')) {
            $schema->table('laralyze_executions', function (Blueprint $table) {
                $table->dropColumn('calls');
            });
        }
    }
};
