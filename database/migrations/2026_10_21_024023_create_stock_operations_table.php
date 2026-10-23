<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_number', 50)->unique();
            $table->string('operation_type', 20);
            $table->date('occurred_on');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps(6);
            $table->index(['operation_type', 'occurred_on']);
        });

        Schema::create('stock_operation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_operation_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->foreignId('inventory_lot_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_delta', 19, 4);
            $table->decimal('quantity_before', 19, 4);
            $table->decimal('quantity_after', 19, 4);
            $table->decimal('unit_cost', 19, 4)->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamps(6);
            $table->unique(['stock_operation_id', 'line_number'], 'stock_operation_line_number_unique');
            $table->index(['inventory_lot_id', 'created_at']);
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_operation_lines');
        Schema::dropIfExists('stock_operations');
    }
};
