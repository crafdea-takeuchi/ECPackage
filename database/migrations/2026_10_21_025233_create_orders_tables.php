<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_number', 50)->unique();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->string('order_source', 20)->default('admin');
            $table->date('ordered_on');
            $table->string('status', 20)->default('draft');
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at', 6)->nullable();
            $table->foreignId('stock_operation_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(6);
            $table->index(['customer_id', 'ordered_on']);
            $table->index(['status', 'ordered_on']);
        });

        Schema::create('order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_sku', 50);
            $table->string('product_name', 200);
            $table->string('unit_name', 30);
            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_sale_price', 19, 4);
            $table->decimal('line_amount', 19, 4);
            $table->timestamps(6);
            $table->unique(['order_id', 'line_number'], 'order_line_number_unique');
            $table->index(['product_id', 'created_at']);
        });

        Schema::create('order_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_lot_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_operation_line_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_cost', 19, 4)->nullable();
            $table->timestamps(6);
            $table->unique(['order_line_id', 'inventory_lot_id'], 'order_allocation_line_lot_unique');
            $table->index(['inventory_lot_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_allocations');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
    }
};
