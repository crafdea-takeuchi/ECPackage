<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_settings', function (Blueprint $table): void {
            $table->string('google_analytics_id', 20)->nullable()->after('system_name');
        });
    }

    public function down(): void
    {
        Schema::table('order_settings', function (Blueprint $table): void {
            $table->dropColumn('google_analytics_id');
        });
    }
};
