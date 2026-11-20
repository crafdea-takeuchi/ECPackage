<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_settings', function (Blueprint $table): void {
            $table->string('system_name', 100)->default('EC02')->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('order_settings', function (Blueprint $table): void {
            $table->dropColumn('system_name');
        });
    }
};
