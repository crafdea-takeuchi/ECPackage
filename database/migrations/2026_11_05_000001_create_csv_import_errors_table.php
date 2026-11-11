<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csv_import_errors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('csv_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('column')->nullable();
            $table->text('message');
            $table->json('raw_data')->nullable();
            $table->timestamps(6);
            $table->index(['csv_import_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csv_import_errors');
    }
};
