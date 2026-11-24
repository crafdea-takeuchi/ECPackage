<?php

namespace App\Models;

use Database\Factories\ProductImageUploadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 商品の保存前に1枚ずつアップロードされた画像。商品の保存時に商品画像へ移され、このレコードは削除される。
 * 商品に紐付けられないまま残ったものは、ファイルごと定期的に削除する。
 */
class ProductImageUpload extends Model
{
    /** @use HasFactory<ProductImageUploadFactory> */
    use HasFactory, Prunable;

    public const RETENTION_HOURS = 24;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['created_by', 'disk', 'path', 'thumbnail_path', 'mime_type', 'size_bytes', 'width', 'height'];

    protected $hidden = ['disk', 'path', 'thumbnail_path'];

    /**
     * 商品画像へ移すときに使う属性。
     *
     * @return array{disk: string, path: string, thumbnail_path: string, mime_type: string, size_bytes: int, width: int, height: int}
     */
    public function imageAttributes(): array
    {
        return $this->only(['disk', 'path', 'thumbnail_path', 'mime_type', 'size_bytes', 'width', 'height']);
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subHours(self::RETENTION_HOURS));
    }

    protected function pruning(): void
    {
        try {
            Storage::disk($this->disk)->delete([$this->path, $this->thumbnail_path]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
