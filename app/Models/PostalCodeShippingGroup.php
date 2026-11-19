<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PostalCodeShippingGroup extends Model
{
    protected $fillable = ['name', 'shipping_fee', 'free_shipping_threshold'];

    protected function casts(): array
    {
        return [
            'shipping_fee' => 'decimal:4',
            'free_shipping_threshold' => 'decimal:4',
        ];
    }

    public function postalCodes(): HasMany
    {
        return $this->hasMany(PostalCodeShippingPostalCode::class)->orderBy('postal_code');
    }
}
