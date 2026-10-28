<?php

namespace Database\Seeders;

use App\Models\TaxRate;
use Illuminate\Database\Seeder;

class TaxRateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['reduced', '軽減税率', '8.00'], ['standard', '標準税率', '10.00']] as [$code, $name, $rate]) {
            TaxRate::firstOrCreate(['code' => $code], ['name' => $name, 'rate' => $rate, 'status' => 'active']);
        }
    }
}
