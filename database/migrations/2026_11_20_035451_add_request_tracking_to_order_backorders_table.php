<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_backorders', function (Blueprint $table): void {
            $table->dateTime('requested_at', 6)->nullable()->after('remaining_quantity');
            $table->foreignId('requested_by')->nullable()->after('requested_at')->constrained('users')->nullOnDelete();
            $table->index(['requested_at', 'remaining_quantity'], 'order_backorders_request_status_index');
        });

        DB::table('order_backorders')->whereNull('requested_at')->update([
            'requested_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('order_backorders', function (Blueprint $table): void {
            $table->dropIndex('order_backorders_request_status_index');
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn('requested_at');
        });
    }
};
