<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_settings', function (Blueprint $table): void {
            $table->decimal('cash_on_delivery_fee', 19, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_settings', function (Blueprint $table): void {
            $table->dropColumn('cash_on_delivery_fee');
        });
    }
};
