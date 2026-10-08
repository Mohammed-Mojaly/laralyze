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
        // Log entries at or above the configured level, with where they were written.
        Schema::connection($this->getConnection())->create('laralyze_logs', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 26)->unique();
            $table->bigInteger('logged_at');
            $table->string('level', 16);
            $table->text('message');
            $table->text('context')->nullable();
            // The exception it reported, for its page.
            $table->char('exception', 32)->nullable();
            // The request, job or command it was written in.
            $table->char('execution', 26)->nullable();
            $table->string('type', 16)->nullable();
            $table->text('name')->nullable();
            $table->string('user_id', 64)->nullable();
            $table->string('server', 128);

            $table->index('logged_at');
            $table->index(['level', 'logged_at']);
            $table->index(['user_id', 'logged_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('laralyze_logs');
    }
};
