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
        // What each request, job and command recorded, waiting for the digest
        // to merge it into Laralyze's tables. Plain inserts: no locks to fight over.
        Schema::connection($this->getConnection())->create('laralyze_ingest', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('created_at')->index();
            $table->string('server', 128);
            $table->longText('payload');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('laralyze_ingest');
    }
};
