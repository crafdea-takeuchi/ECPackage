<?php

namespace App\Actions\Orders;

class CalculateTaxes
{
    /**
     * 税率区分ごとに金額を合計し、区分ごとに1回だけ1円未満を切り捨てて税額を求める。
     * 税率区分のない明細は非課税（0%）として扱う。
     *
     * @param  iterable<array{tax_rate_name: ?string, tax_rate: string|float|int, amount: string}>  $lines
     * @return array{subtotal: string, taxes: list<array{name: string, rate: string, taxable_amount: string, tax_amount: string}>, tax_amount: string, total: string}
     */
    public function handle(iterable $lines): array
    {
        $groups = [];
        $subtotal = '0';
        foreach ($lines as $line) {
            $name = $line['tax_rate_name'] ?? '非課税';
            $rate = $line['tax_rate_name'] === null ? '0.00' : bcadd((string) $line['tax_rate'], '0', 2);
            $key = $name.'|'.$rate;
            $groups[$key] ??= ['name' => $name, 'rate' => $rate, 'taxable_amount' => '0.0000'];
            $groups[$key]['taxable_amount'] = bcadd($groups[$key]['taxable_amount'], $line['amount'], 4);
            $subtotal = bcadd($subtotal, $line['amount'], 4);
        }

        $taxAmount = '0';
        $taxes = array_map(function (array $group) use (&$taxAmount): array {
            $group['tax_amount'] = bcadd(bcdiv(bcmul($group['taxable_amount'], $group['rate'], 6), '100', 0), '0', 4);
            $taxAmount = bcadd($taxAmount, $group['tax_amount'], 4);

            return $group;
        }, array_values($groups));
        usort($taxes, fn (array $a, array $b): int => bccomp($b['rate'], $a['rate'], 2));

        return ['subtotal' => $subtotal, 'taxes' => $taxes, 'tax_amount' => $taxAmount, 'total' => bcadd($subtotal, $taxAmount, 4)];
    }
}
