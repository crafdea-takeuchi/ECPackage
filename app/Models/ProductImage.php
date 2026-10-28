<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['disk', 'path', 'thumbnail_path', 'sort_order', 'alt_text', 'mime_type', 'size_bytes', 'width', 'height'];

    protected $hidden = ['disk', 'path', 'thumbnail_path'];

    protected $appends = ['url', 'thumbnail_url'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getUrlAttribute(): string
    {
        return route('product-images.show', [$this, 'full'], false).'?v='.substr(hash('sha256', $this->path), 0, 12);
    }

    public function getThumbnailUrlAttribute(): string
    {
        return route('product-images.show', [$this, 'thumbnail'], false).'?v='.substr(hash('sha256', $this->thumbnail_path), 0, 12);
    }
}
