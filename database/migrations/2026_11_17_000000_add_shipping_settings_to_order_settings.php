<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_settings', function (Blueprint $table): void {
            $table->json('shipping_zone_fees')->nullable();
            // 税込の商品小計が基準。送料・代引き手数料などは含めず、判定処理は別途実装する。
            $table->json('free_shipping_thresholds')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_settings', function (Blueprint $table): void {
            $table->dropColumn(['shipping_zone_fees', 'free_shipping_thresholds']);
        });
    }
};
