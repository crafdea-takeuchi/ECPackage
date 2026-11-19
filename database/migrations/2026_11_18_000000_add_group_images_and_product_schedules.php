<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id')->nullable()->change();
            $table->foreignId('product_group_id')->nullable()->unique()->constrained()->cascadeOnDelete();
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dateTime('publication_starts_at')->nullable();
            $table->dateTime('publication_ends_at')->nullable();
            $table->dateTime('sale_starts_at')->nullable();
            $table->dateTime('sale_ends_at')->nullable();
            $table->index(['registration_status', 'publication_status', 'publication_starts_at', 'publication_ends_at'], 'products_publication_window');
        });
    }

    public function down(): void
    {
        // Preserve stored files: remove parent images through the management UI before rollback.
        if (DB::table('product_images')->whereNotNull('product_group_id')->exists()) {
            throw new RuntimeException('基本商品の代表画像を管理画面から削除してからロールバックしてください。');
        }
        Schema::table('product_images', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_group_id');
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_publication_window');
            $table->dropColumn(['publication_starts_at', 'publication_ends_at', 'sale_starts_at', 'sale_ends_at']);
        });
    }
};
