<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostalCodeShippingPostalCode extends Model
{
    protected $fillable = ['postal_code_shipping_group_id', 'postal_code'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(PostalCodeShippingGroup::class, 'postal_code_shipping_group_id');
    }

    public static function normalize(?string $postalCode): ?string
    {
        if ($postalCode === null) {
            return null;
        }

        $normalized = mb_convert_kana(trim($postalCode), 'a');
        $normalized = preg_replace('/[\s\-‐‑–—―ー－]/u', '', $normalized);

        return preg_match('/\A\d{7}\z/', (string) $normalized) === 1 ? $normalized : null;
    }
}
