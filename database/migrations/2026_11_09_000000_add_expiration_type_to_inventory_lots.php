<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['purchase_receipt_lines', 'inventory_lots'] as $name) {
            if (! Schema::hasColumn($name, 'expiration_type')) {
                Schema::table($name, function (Blueprint $table): void {
                    $table->string('expiration_type', 20)->nullable()->after('expires_on');
                });
            }
        }

        if (! Schema::hasIndex('inventory_lots', 'inventory_lots_expiration_index')) {
            Schema::table('inventory_lots', function (Blueprint $table): void {
                $table->index(['expiration_type', 'expires_on'], 'inventory_lots_expiration_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->dropIndex('inventory_lots_expiration_index');
            $table->dropColumn('expiration_type');
        });
        Schema::table('purchase_receipt_lines', function (Blueprint $table): void {
            $table->dropColumn('expiration_type');
        });
    }
};
