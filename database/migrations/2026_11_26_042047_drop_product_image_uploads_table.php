<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * 商品画像はアップロード時点で商品に紐づけるようになったため、一時保存のテーブルを削除する。
     * 残っている一時保存の画像ファイルはどの商品にも使われていないので、あわせて削除する。
     */
    public function up(): void
    {
        if (! Schema::hasTable('product_image_uploads')) {
            return;
        }
        DB::table('product_image_uploads')->orderBy('id')->each(function (object $upload): void {
            try {
                Storage::disk($upload->disk)->delete([$upload->path, $upload->thumbnail_path]);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
        Schema::drop('product_image_uploads');
    }

    public function down(): void
    {
        Schema::create('product_image_uploads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('disk', 50);
            $table->string('path', 255);
            $table->string('thumbnail_path', 255);
            $table->string('mime_type', 50);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamps(6);
            $table->index('created_at');
        });
    }
};
