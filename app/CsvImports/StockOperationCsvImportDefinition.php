<?php

namespace App\CsvImports;

use App\Actions\Inventory\RecordStockOperation;
use App\Contracts\CsvImportDefinition;
use App\Models\CsvImport;
use App\Models\InventoryLot;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use LogicException;

class StockOperationCsvImportDefinition implements CsvImportDefinition
{
    private const OPERATION_TYPE = '操作種別';

    private const OCCURRED_ON = '処理日';

    private const PRODUCT_CODE = '商品コード';

    private const LOT_NUMBER = 'ロット番号';

    private const SUPPLIER_CODE = '仕入先コード';

    private const QUANTITY = '数量';

    private const REASON = '理由';

    private const NOTES = '備考';

    /** @var Collection<string, Collection<int, InventoryLot>>|null */
    private ?Collection $lots = null;

    /** @var array<int, string> */
    private array $projectedQuantities = [];

    public function __construct(private readonly RecordStockOperation $recordStockOperation) {}

    public function type(): string
    {
        return 'stock_operations';
    }

    /** @return list<string> */
    public function requiredHeaders(): array
    {
        return [
            self::OPERATION_TYPE,
            self::OCCURRED_ON,
            self::PRODUCT_CODE,
            self::LOT_NUMBER,
            self::SUPPLIER_CODE,
            self::QUANTITY,
            self::REASON,
            self::NOTES,
        ];
    }

    public function validateRow(array $row, int $rowNumber): array
    {
        $errors = [];
        $operation = $this->operation($this->value($row, self::OPERATION_TYPE));
        $occurredOn = $this->value($row, self::OCCURRED_ON);
        $productCode = strtoupper($this->value($row, self::PRODUCT_CODE));
        $lotNumber = $this->value($row, self::LOT_NUMBER);
        $supplierCode = strtoupper($this->value($row, self::SUPPLIER_CODE));
        $quantity = $this->value($row, self::QUANTITY);
        $reason = $this->value($row, self::REASON);
        $notes = $this->value($row, self::NOTES);

        if ($operation === null) {
            $this->error($errors, self::OPERATION_TYPE, '操作種別は「通常出庫」「在庫増加」「在庫減少」のいずれかを入力してください。');
        }
        if (! $this->isDate($occurredOn)) {
            $this->error($errors, self::OCCURRED_ON, '処理日はYYYY-MM-DD形式で入力してください。');
        }
        if ($productCode === '') {
            $this->error($errors, self::PRODUCT_CODE, '商品コードを入力してください。');
        }
        if ($lotNumber === '') {
            $this->error($errors, self::LOT_NUMBER, 'ロット番号を入力してください。');
        } elseif (mb_strlen($lotNumber) > 100) {
            $this->error($errors, self::LOT_NUMBER, 'ロット番号は100文字以内で入力してください。');
        }

        $lot = null;
        if ($productCode !== '' && $lotNumber !== '') {
            $matches = $this->matchingLots($productCode, $lotNumber, $supplierCode);
            if ($matches->isEmpty()) {
                $column = $supplierCode === '' ? self::LOT_NUMBER : self::SUPPLIER_CODE;
                $this->error($errors, $column, '指定した商品・ロット・仕入先に一致する在庫ロットが見つかりません。');
            } elseif ($matches->count() > 1) {
                $message = $supplierCode === ''
                    ? '一致する在庫ロットが複数あります。仕入先コードを指定して一意にしてください。'
                    : '指定した商品・ロット・仕入先に一致する在庫ロットが複数あります。ロットの登録内容を確認してください。';
                $this->error($errors, self::LOT_NUMBER, $message);
            } else {
                $lot = $matches->first();
            }
        }

        if (! $this->isPositiveDecimal($quantity)) {
            $this->error($errors, self::QUANTITY, '数量は0より大きい数値を小数4桁以内で入力してください。');
        } elseif ($lot?->product?->unit !== null && $this->decimalPlaces($quantity) > $lot->product->unit->quantity_scale) {
            $scale = $lot->product->unit->quantity_scale;
            $message = $scale === 0
                ? 'この販売商品の数量は整数で入力してください。'
                : "この販売商品の数量は小数{$scale}桁以内で入力してください。";
            $this->error($errors, self::QUANTITY, $message);
        }

        if ($operation !== null && $lot !== null && $this->isPositiveDecimal($quantity)) {
            $this->projectQuantity($lot, $operation, $quantity, $errors);
        }

        if (mb_strlen($reason) > 500) {
            $this->error($errors, self::REASON, '理由は500文字以内で入力してください。');
        }
        if (mb_strlen($notes) > 2000) {
            $this->error($errors, self::NOTES, '備考は2000文字以内で入力してください。');
        }

        return $errors;
    }

    public function importRows(array $rows, User $user, CsvImport $import): int
    {
        $this->lots = null;

        foreach ($rows as $row) {
            $operation = $this->operation($this->value($row, self::OPERATION_TYPE));
            $productCode = strtoupper($this->value($row, self::PRODUCT_CODE));
            $lotNumber = $this->value($row, self::LOT_NUMBER);
            $supplierCode = strtoupper($this->value($row, self::SUPPLIER_CODE));
            $matches = $this->matchingLots($productCode, $lotNumber, $supplierCode);

            if ($operation === null || $matches->count() !== 1) {
                throw new LogicException("商品「{$productCode}」のロット「{$lotNumber}」の登録条件が検証時から変更されています。");
            }

            $lot = $matches->first();
            $quantity = $this->value($row, self::QUANTITY);
            $operationType = $operation === 'outbound' ? 'outbound' : 'adjustment';
            $operationQuantity = $operation === 'adjustment_decrease'
                ? bcmul($quantity, '-1', 4)
                : $quantity;

            $this->recordStockOperation->handle([
                'operation_type' => $operationType,
                'occurred_on' => $this->value($row, self::OCCURRED_ON),
                'notes' => $this->nullable($this->value($row, self::NOTES)),
                'lines' => [[
                    'inventory_lot_id' => $lot->id,
                    'quantity' => $operationQuantity,
                    'reason' => $this->nullable($this->value($row, self::REASON)),
                ]],
            ], $user);
        }

        return count($rows);
    }

    public function templateRows(): array
    {
        return [];
    }

    /** @return Collection<int, InventoryLot> */
    private function matchingLots(string $productCode, string $lotNumber, string $supplierCode): Collection
    {
        $key = $productCode.'|'.mb_strtolower($lotNumber);
        $matches = $this->lotMap()->get($key, collect());

        if ($supplierCode !== '') {
            $matches = $matches->filter(
                fn (InventoryLot $lot): bool => strtoupper((string) $lot->supplier?->code) === $supplierCode,
            );
        }

        return $matches->values();
    }

    /** @return Collection<string, Collection<int, InventoryLot>> */
    private function lotMap(): Collection
    {
        return $this->lots ??= InventoryLot::query()
            ->select([
                'id',
                'product_id',
                'supplier_id',
                'lot_number',
                'current_quantity',
                'unit_purchase_price',
            ])
            ->with([
                'product:id,sku,unit_id',
                'product.unit:id,quantity_scale',
                'supplier:id,code',
            ])
            ->get()
            ->groupBy(fn (InventoryLot $lot): string => strtoupper($lot->product->sku).'|'.mb_strtolower($lot->lot_number));
    }

    /** @param list<array{column: string|null, message: string}> $errors */
    private function projectQuantity(InventoryLot $lot, string $operation, string $quantity, array &$errors): void
    {
        $before = $this->projectedQuantities[$lot->id] ?? (string) $lot->current_quantity;
        $delta = in_array($operation, ['outbound', 'adjustment_decrease'], true)
            ? bcmul($quantity, '-1', 4)
            : bcadd($quantity, '0', 4);
        $after = bcadd($before, $delta, 4);

        if (bccomp($after, '0', 4) === -1) {
            $this->error($errors, self::QUANTITY, "CSV内の処理順ではロット {$lot->lot_number} の在庫（{$before}）を超えて減らせません。");

            return;
        }

        $this->projectedQuantities[$lot->id] = $after;
    }

    private function operation(string $value): ?string
    {
        return [
            '通常出庫' => 'outbound',
            'outbound' => 'outbound',
            '在庫増加' => 'adjustment_increase',
            'adjustment_increase' => 'adjustment_increase',
            '在庫減少' => 'adjustment_decrease',
            'adjustment_decrease' => 'adjustment_decrease',
        ][$value] ?? null;
    }

    private function isDate(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function isPositiveDecimal(string $value): bool
    {
        return preg_match('/^\d{1,15}(\.\d{1,4})?$/', $value) === 1 && bccomp($value, '0', 4) === 1;
    }

    private function decimalPlaces(string $value): int
    {
        if (! str_contains($value, '.')) {
            return 0;
        }

        return strlen(rtrim(substr(strrchr($value, '.'), 1), '0'));
    }

    private function value(array $row, string $key): string
    {
        return trim((string) ($row[$key] ?? ''));
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /** @param list<array{column: string|null, message: string}> $errors */
    private function error(array &$errors, string $column, string $message): void
    {
        $errors[] = ['column' => $column, 'message' => $message];
    }
}
