<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->decimal('backordered_initial_quantity', 19, 4)->default(0)->after('quantity');
            $table->dateTime('backorder_fulfilled_at', 6)->nullable()->after('backordered_quantity');
            $table->index(['product_id', 'backordered_quantity'], 'order_lines_backorder_index');
        });
        DB::table('order_lines')->where('backordered_quantity', '>', 0)->update([
            'backordered_initial_quantity' => DB::raw('backordered_quantity'),
        ]);
        Schema::table('orders', function (Blueprint $table): void {
            $table->dateTime('backorder_completed_at', 6)->nullable()->after('confirmed_at');
        });
        Schema::table('order_allocations', function (Blueprint $table): void {
            $table->index(['order_line_id', 'inventory_lot_id'], 'order_allocation_line_lot_index');
            $table->dropUnique('order_allocation_line_lot_unique');
        });
    }

    public function down(): void
    {
        Schema::table('order_allocations', function (Blueprint $table): void {
            $table->unique(['order_line_id', 'inventory_lot_id'], 'order_allocation_line_lot_unique');
            $table->dropIndex('order_allocation_line_lot_index');
        });
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('backorder_completed_at'));
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropIndex('order_lines_backorder_index');
            $table->dropColumn(['backordered_initial_quantity', 'backorder_fulfilled_at']);
        });
    }
};
