<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('tax_amount', 19, 4)->default(0)->after('subtotal');
        });
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->string('tax_rate_name', 30)->nullable()->after('line_amount');
            $table->decimal('tax_rate', 5, 2)->default(0)->after('tax_rate_name');
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropColumn(['tax_rate_name', 'tax_rate']);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('tax_amount');
        });
    }
};
