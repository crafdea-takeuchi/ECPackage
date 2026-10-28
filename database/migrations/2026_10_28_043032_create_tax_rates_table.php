<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 30);
            $table->decimal('rate', 5, 2);
            $table->string('status', 20)->default('active');
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
