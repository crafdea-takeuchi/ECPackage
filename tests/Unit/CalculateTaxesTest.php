<?php

namespace Tests\Unit;

use App\Actions\Orders\CalculateTaxes;
use PHPUnit\Framework\TestCase;

class CalculateTaxesTest extends TestCase
{
    public function test_taxes_are_grouped_by_rate_and_truncated_once_per_group(): void
    {
        $result = (new CalculateTaxes)->handle([
            ['tax_rate_name' => '軽減税率', 'tax_rate' => '8.00', 'amount' => '10.5000'],
            ['tax_rate_name' => '標準税率', 'tax_rate' => '10.00', 'amount' => '450.2500'],
            ['tax_rate_name' => '軽減税率', 'tax_rate' => '8.00', 'amount' => '10.5000'],
            ['tax_rate_name' => null, 'tax_rate' => '0', 'amount' => '200.0000'],
        ]);

        $this->assertSame('671.2500', $result['subtotal']);
        $this->assertSame([
            ['name' => '標準税率', 'rate' => '10.00', 'taxable_amount' => '450.2500', 'tax_amount' => '45.0000'],
            ['name' => '軽減税率', 'rate' => '8.00', 'taxable_amount' => '21.0000', 'tax_amount' => '1.0000'],
            ['name' => '非課税', 'rate' => '0.00', 'taxable_amount' => '200.0000', 'tax_amount' => '0.0000'],
        ], $result['taxes']);
        $this->assertSame('46.0000', $result['tax_amount']);
        $this->assertSame('717.2500', $result['total']);
    }

    public function test_empty_lines_have_no_taxes(): void
    {
        $result = (new CalculateTaxes)->handle([]);

        $this->assertSame([], $result['taxes']);
        $this->assertSame('0', $result['tax_amount']);
        $this->assertSame('0', $result['subtotal']);
    }
}
