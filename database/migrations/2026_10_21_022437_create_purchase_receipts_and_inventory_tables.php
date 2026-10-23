<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('receipt_number', 50)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('received_on');
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('posted_at', 6)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(6);
            $table->index(['supplier_id', 'received_on']);
            $table->index(['status', 'received_on']);
        });

        Schema::create('purchase_receipt_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_receipt_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_supplier_id')->constrained('product_suppliers')->restrictOnDelete();
            $table->string('lot_number', 100);
            $table->date('manufactured_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_purchase_price', 19, 4);
            $table->decimal('purchase_amount', 19, 4);
            $table->timestamps(6);
            $table->unique(['purchase_receipt_id', 'line_number'], 'purchase_receipt_line_number_unique');
            $table->index(['product_id', 'lot_number']);
        });

        Schema::create('inventory_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_receipt_line_id')->unique()->constrained()->restrictOnDelete();
            $table->string('lot_number', 100);
            $table->date('received_on');
            $table->date('manufactured_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->decimal('initial_quantity', 19, 4);
            $table->decimal('current_quantity', 19, 4);
            $table->decimal('unit_purchase_price', 19, 4);
            $table->string('status', 20)->default('active');
            $table->timestamps(6);
            $table->index(['product_id', 'status', 'received_on'], 'inventory_lot_product_lookup');
            $table->index(['supplier_id', 'received_on']);
            $table->index('lot_number');
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_lot_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('movement_type', 30);
            $table->date('occurred_on');
            $table->decimal('quantity_delta', 19, 4);
            $table->decimal('unit_cost', 19, 4)->nullable();
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps(6);
            $table->unique(['source_type', 'source_id'], 'stock_movement_source_unique');
            $table->index(['product_id', 'occurred_on']);
            $table->index(['movement_type', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('inventory_lots');
        Schema::dropIfExists('purchase_receipt_lines');
        Schema::dropIfExists('purchase_receipts');
    }
};
