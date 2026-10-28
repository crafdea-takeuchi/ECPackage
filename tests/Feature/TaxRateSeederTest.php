<?php

namespace Tests\Feature;

use App\Models\TaxRate;
use Database\Seeders\TaxRateSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TaxRateSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_tax_rate_seeder_creates_reduced_and_standard_rates_and_is_idempotent(): void
    {
        $this->seed(TaxRateSeeder::class);
        $this->seed(TaxRateSeeder::class);

        $this->assertDatabaseCount('tax_rates', 2);
        $reduced = TaxRate::where('code', 'reduced')->firstOrFail();
        $standard = TaxRate::where('code', 'standard')->firstOrFail();
        $this->assertSame(['軽減税率', '8.00', 'active'], [$reduced->name, $reduced->rate, $reduced->status]);
        $this->assertSame(['標準税率', '10.00', 'active'], [$standard->name, $standard->rate, $standard->status]);
    }
}
