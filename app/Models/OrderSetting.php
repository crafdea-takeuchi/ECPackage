<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderSetting extends Model
{
    protected $fillable = ['cancellable_statuses'];

    protected function casts(): array
    {
        return ['cancellable_statuses' => 'array'];
    }

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], ['cancellable_statuses' => ['draft', 'confirmed', 'preparing']]);
    }
}
