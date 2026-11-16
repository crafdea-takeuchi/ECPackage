<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_settings', function (Blueprint $table): void {
            $table->text('bank_transfer')->nullable();
            $table->foreignId('bank_transfer_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('bank_transfer_updated_at', 6)->nullable();
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->text('bank_transfer_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('bank_transfer_snapshot');
        });
        Schema::table('order_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bank_transfer_updated_by');
            $table->dropColumn(['bank_transfer', 'bank_transfer_updated_at']);
        });
    }
};
