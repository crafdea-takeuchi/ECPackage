<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['piece', '個', 0], ['set', 'セット', 0], ['kg', 'kg', 3], ['m', 'm', 3]] as [$code, $name, $scale]) {
            Unit::firstOrCreate(['code' => $code], ['name' => $name, 'quantity_scale' => $scale, 'status' => 'active']);
        }
    }
}
