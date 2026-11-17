<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderLine;

class OrderTaxDisplay
{
    public function __construct(private CalculateTaxes $calculateTaxes) {}

    /**
     * 注文時のスナップショットから表示額を配分し、保存済み金額と一致する場合だけ返す。
     *
     * @return array{line_taxes: array<int, string|null>, taxes: list<array{name: string, rate: string, taxable_amount: string, tax_amount: string}>}
     */
    public function handle(Order $order): array
    {
        $amounts = $this->calculateTaxes->handle($order->lines->map(fn (OrderLine $line): array => [
            'tax_rate_name' => $line->tax_rate_name,
            'tax_rate' => $line->tax_rate,
            'amount' => $line->line_amount,
        ]));
        $amounts['total'] = bcadd($amounts['total'], (string) ($order->payment_fee ?? '0'), 4);
        $unavailable = ['line_taxes' => $order->lines->mapWithKeys(fn (OrderLine $line): array => [$line->id => null])->all(), 'taxes' => []];

        foreach (['subtotal', 'tax_amount', 'total'] as $field) {
            if (bccomp($amounts[$field], (string) $order->{$field}, 4) !== 0) {
                return $unavailable;
            }
        }

        $lineTaxes = [];
        foreach ($amounts['taxes'] as $tax) {
            $lines = $order->lines->filter(fn (OrderLine $line): bool => ($line->tax_rate_name ?? '非課税') === $tax['name']
                && bccomp($line->tax_rate_name === null ? '0' : $line->tax_rate, $tax['rate'], 2) === 0);
            $allocated = '0';
            $remainders = [];
            foreach ($lines as $line) {
                $rawTax = bcdiv(bcmul($line->line_amount, $tax['rate'], 6), '100', 8);
                $lineTax = bcadd(bcdiv($rawTax, '1', 0), '0', 4);
                $lineTaxes[$line->id] = $lineTax;
                $allocated = bcadd($allocated, $lineTax, 4);
                $remainders[] = ['id' => $line->id, 'line_number' => $line->line_number, 'fraction' => bcsub($rawTax, $lineTax, 8)];
            }
            usort($remainders, fn (array $a, array $b): int => bccomp($b['fraction'], $a['fraction'], 8) ?: $a['line_number'] <=> $b['line_number']);
            $remaining = (int) bcsub($tax['tax_amount'], $allocated, 0);
            foreach (array_slice($remainders, 0, $remaining) as $line) {
                $lineTaxes[$line['id']] = bcadd($lineTaxes[$line['id']], '1', 4);
            }
        }

        return ['line_taxes' => $lineTaxes, 'taxes' => $amounts['taxes']];
    }
}
