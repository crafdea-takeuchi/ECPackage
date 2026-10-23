<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 30);
            $table->unsignedTinyInteger('quantity_scale')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps(6);
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 50)->unique();
            $table->string('name', 200);
            $table->string('kind', 20)->default('single');
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->string('registration_status', 20)->default('provisional');
            $table->string('publication_status', 20)->default('private');
            $table->decimal('default_sale_price', 19, 4)->nullable();
            $table->json('attributes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at', 6)->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(6);
            $table->index(['registration_status', 'publication_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('units');
    }
};
