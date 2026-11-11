<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csv_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 50);
            $table->string('original_filename');
            $table->string('disk', 50);
            $table->string('path', 500);
            $table->char('file_hash', 64);
            $table->string('encoding', 30)->nullable();
            $table->string('status', 30);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->json('options')->nullable();
            $table->text('system_error')->nullable();
            $table->dateTime('started_at', 6)->nullable();
            $table->dateTime('finished_at', 6)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps(6);
            $table->index(['type', 'status', 'created_at']);
            $table->index(['file_hash', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csv_imports');
    }
};
