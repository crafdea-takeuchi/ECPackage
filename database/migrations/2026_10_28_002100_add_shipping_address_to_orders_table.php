<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('shipping_name', 100)->nullable()->after('notes');
            $table->string('shipping_postal_code', 8)->nullable()->after('shipping_name');
            $table->string('shipping_prefecture', 10)->nullable()->after('shipping_postal_code');
            $table->string('shipping_address_line1', 200)->nullable()->after('shipping_prefecture');
            $table->string('shipping_address_line2', 200)->nullable()->after('shipping_address_line1');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'shipping_name',
                'shipping_postal_code',
                'shipping_prefecture',
                'shipping_address_line1',
                'shipping_address_line2',
            ]);
        });
    }
};
