<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->index(); // completed | failed
            $table->string('connection', 100)->nullable()->index();
            $table->string('label', 100)->nullable();
            $table->string('file', 500)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->json('drivers')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_backup_runs');
    }
};
