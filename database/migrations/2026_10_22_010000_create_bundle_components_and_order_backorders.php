<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('units')->where('code', 'set')->exists()) {
            DB::table('units')->insert(['code' => 'set', 'name' => 'セット', 'quantity_scale' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::create('product_bundle_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bundle_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('component_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 19, 4);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps(6);
            $table->unique(['bundle_product_id', 'component_product_id'], 'bundle_component_unique');
        });

        Schema::create('order_line_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_product_id')->constrained('products')->restrictOnDelete();
            $table->string('component_sku', 50);
            $table->string('component_name', 200);
            $table->string('unit_name', 30);
            $table->decimal('quantity_per_set', 19, 4);
            $table->decimal('required_quantity', 19, 4);
            $table->timestamps(6);
            $table->unique(['order_line_id', 'component_product_id'], 'order_line_component_unique');
        });

        Schema::create('order_backorders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_line_component_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('stock_product_id')->constrained('products')->restrictOnDelete();
            $table->string('stock_product_sku', 50);
            $table->string('stock_product_name', 200);
            $table->string('unit_name', 30);
            $table->decimal('required_quantity', 19, 4);
            $table->decimal('initial_quantity', 19, 4);
            $table->decimal('remaining_quantity', 19, 4);
            $table->dateTime('fulfilled_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['stock_product_id', 'remaining_quantity'], 'order_backorders_stock_remaining_index');
            $table->index(['order_line_id', 'fulfilled_at']);
        });

        Schema::table('order_allocations', function (Blueprint $table): void {
            $table->foreignId('order_line_component_id')->nullable()->after('order_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_backorder_id')->nullable()->after('order_line_component_id')->constrained()->restrictOnDelete();
        });

        $unitNames = DB::table('products')->join('units', 'units.id', '=', 'products.unit_id')->pluck('units.name', 'products.id');
        foreach (DB::table('order_lines')->where('backordered_initial_quantity', '>', 0)->orderBy('id')->get() as $line) {
            DB::table('order_backorders')->insert([
                'order_line_id' => $line->id,
                'stock_product_id' => $line->product_id,
                'stock_product_sku' => $line->product_sku,
                'stock_product_name' => $line->product_name,
                'unit_name' => $unitNames[$line->product_id] ?? $line->unit_name,
                'required_quantity' => $line->quantity,
                'initial_quantity' => $line->backordered_initial_quantity,
                'remaining_quantity' => $line->backordered_quantity,
                'fulfilled_at' => $line->backorder_fulfilled_at,
                'created_at' => $line->created_at,
                'updated_at' => $line->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('order_allocations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('order_backorder_id');
            $table->dropConstrainedForeignId('order_line_component_id');
        });
        Schema::dropIfExists('order_backorders');
        Schema::dropIfExists('order_line_components');
        Schema::dropIfExists('product_bundle_components');
    }
};
