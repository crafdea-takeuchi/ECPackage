<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderSetting extends Model
{
    protected $fillable = ['cancellable_statuses'];

    protected $hidden = ['bank_transfer', 'bank_transfer_updated_by', 'bank_transfer_updated_at'];

    protected function casts(): array
    {
        return ['cancellable_statuses' => 'array', 'bank_transfer' => 'encrypted:array', 'bank_transfer_updated_at' => 'datetime'];
    }

    public static function current(): self
    {
        return static::unguarded(fn (): self => static::firstOrCreate(['id' => 1], ['cancellable_statuses' => ['draft', 'confirmed', 'preparing']]));
    }
}
