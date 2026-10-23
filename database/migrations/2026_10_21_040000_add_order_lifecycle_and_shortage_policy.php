<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('shortage_policy', 20)->default('reject')->after('publication_status');
        });
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->decimal('backordered_quantity', 19, 4)->default(0)->after('quantity');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('cancelled_by')->nullable()->after('confirmed_at')->constrained('users')->restrictOnDelete();
            $table->dateTime('cancelled_at', 6)->nullable()->after('cancelled_by');
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_at');
            $table->foreignId('cancellation_stock_operation_id')->nullable()->unique()->after('stock_operation_id')->constrained('stock_operations')->restrictOnDelete();
        });
        Schema::create('order_settings', function (Blueprint $table): void {
            $table->id();
            $table->json('cancellable_statuses');
            $table->timestamps(6);
        });
        DB::table('order_settings')->insert([
            'id' => 1,
            'cancellable_statuses' => json_encode(['draft', 'confirmed', 'preparing'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_settings');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancellation_stock_operation_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
        Schema::table('order_lines', fn (Blueprint $table) => $table->dropColumn('backordered_quantity'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('shortage_policy'));
    }
};
