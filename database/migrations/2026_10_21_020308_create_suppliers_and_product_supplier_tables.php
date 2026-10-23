<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->string('contact_name', 100)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('address', 500)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(6);
            $table->index(['status', 'name']);
        });

        Schema::create('product_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('supplier_product_code', 100)->nullable();
            $table->decimal('default_purchase_price', 19, 4)->nullable();
            $table->decimal('minimum_order_quantity', 19, 4)->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps(6);
            $table->unique(['supplier_id', 'product_id']);
            $table->index(['product_id', 'is_primary', 'status'], 'product_supplier_primary_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_suppliers');
        Schema::dropIfExists('suppliers');
    }
};
