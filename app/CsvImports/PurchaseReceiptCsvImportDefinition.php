<?php

namespace App\CsvImports;

use App\Actions\Inventory\ReceiptExpiration;
use App\Contracts\CsvImportDefinition;
use App\Exceptions\CsvImportFormatException;
use App\Models\CsvImport;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseReceipt;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class PurchaseReceiptCsvImportDefinition implements CsvImportDefinition
{
    private const RECEIPT_NUMBER = '入庫番号';

    private const RECEIVED_ON = '入庫日';

    private const SUPPLIER_CODE = '仕入先コード';

    private const PRODUCT_CODE = '商品コード';

    private const LOT_NUMBER = 'ロット番号';

    private const QUANTITY = '入庫数量';

    private const UNIT_PRICE = '仕入単価';

    private const MANUFACTURED_ON = '製造日';

    private const EXPIRATION_TYPE = '期限種別';

    private const EXPIRES_ON = '期限日';

    private const NOTES = '備考';

    /** @var array<string, true>|null */
    private ?array $existingReceiptNumbers = null;

    private ?Collection $suppliers = null;

    private ?Collection $products = null;

    private ?Collection $purchaseSettings = null;

    /** @var array<string, array{received_on: string, supplier_code: string, notes: string}> */
    private array $receiptContexts = [];

    /** @var array<string, true> */
    private array $seenLines = [];

    public function type(): string
    {
        return 'purchase_receipts';
    }

    /** @return list<string> */
    public function requiredHeaders(): array
    {
        return [
            self::RECEIPT_NUMBER,
            self::RECEIVED_ON,
            self::SUPPLIER_CODE,
            self::PRODUCT_CODE,
            self::LOT_NUMBER,
            self::QUANTITY,
            self::UNIT_PRICE,
            self::MANUFACTURED_ON,
            self::EXPIRATION_TYPE,
            self::EXPIRES_ON,
            self::NOTES,
        ];
    }

    public function validateRow(array $row, int $rowNumber): array
    {
        $errors = [];
        $receiptNumber = $this->value($row, self::RECEIPT_NUMBER);
        $receivedOn = $this->value($row, self::RECEIVED_ON);
        $supplierCode = strtoupper($this->value($row, self::SUPPLIER_CODE));
        $productCode = strtoupper($this->value($row, self::PRODUCT_CODE));
        $lotNumber = $this->value($row, self::LOT_NUMBER);
        $quantity = $this->value($row, self::QUANTITY);
        $unitPrice = $this->value($row, self::UNIT_PRICE);
        $notes = $this->value($row, self::NOTES);

        if ($receiptNumber === '') {
            $this->error($errors, self::RECEIPT_NUMBER, '入庫番号を入力してください。');
        } elseif (mb_strlen($receiptNumber) > 50) {
            $this->error($errors, self::RECEIPT_NUMBER, '入庫番号は50文字以内で入力してください。');
        } elseif (isset($this->existingReceiptNumberMap()[mb_strtolower($receiptNumber)])) {
            $this->error($errors, self::RECEIPT_NUMBER, 'この入庫番号はすでに登録されています。');
        }

        if (! $this->isDate($receivedOn)) {
            $this->error($errors, self::RECEIVED_ON, '入庫日はYYYY-MM-DD形式で入力してください。');
        }

        $supplier = $this->supplierMap()->get($supplierCode);
        if ($supplierCode === '') {
            $this->error($errors, self::SUPPLIER_CODE, '仕入先コードを入力してください。');
        } elseif ($supplier === null) {
            $this->error($errors, self::SUPPLIER_CODE, '有効な仕入先コードが見つかりません。');
        }

        $product = $this->productMap()->get($productCode);
        if ($productCode === '') {
            $this->error($errors, self::PRODUCT_CODE, '商品コードを入力してください。');
        } elseif ($product === null) {
            $this->error($errors, self::PRODUCT_CODE, '有効な販売商品コードが見つかりません。');
        } elseif ($supplier !== null && ! $this->purchaseSettingMap()->has($this->settingKey($supplier->id, $product->id))) {
            $this->error($errors, self::PRODUCT_CODE, 'この仕入先に有効な仕入商品設定がありません。');
        }

        if ($lotNumber === '') {
            $this->error($errors, self::LOT_NUMBER, 'ロット番号を入力してください。');
        } elseif (mb_strlen($lotNumber) > 100) {
            $this->error($errors, self::LOT_NUMBER, 'ロット番号は100文字以内で入力してください。');
        }

        if (! $this->isPositiveDecimal($quantity)) {
            $this->error($errors, self::QUANTITY, '入庫数量は0より大きい数値を小数4桁以内で入力してください。');
        } elseif ($product?->unit !== null && $this->decimalPlaces($quantity) > $product->unit->quantity_scale) {
            $scale = $product->unit->quantity_scale;
            $message = $scale === 0
                ? 'この販売商品の入庫数量は整数で入力してください。'
                : "この販売商品の入庫数量は小数{$scale}桁以内で入力してください。";
            $this->error($errors, self::QUANTITY, $message);
        }

        if (! $this->isNonNegativeDecimal($unitPrice)) {
            $this->error($errors, self::UNIT_PRICE, '仕入単価は0以上の数値を小数4桁以内で入力してください。');
        } elseif ($this->isPositiveDecimal($quantity) && bccomp(bcmul($quantity, $unitPrice, 4), '999999999999999.9999', 4) === 1) {
            $this->error($errors, self::QUANTITY, '入庫金額が上限を超えています。数量または仕入単価を見直してください。');
        }

        array_push($errors, ...$this->expirationErrors($row));
        if (mb_strlen($notes) > 2000) {
            $this->error($errors, self::NOTES, '備考は2000文字以内で入力してください。');
        }

        $this->validateReceiptConsistency($receiptNumber, $receivedOn, $supplierCode, $notes, $errors);
        $this->validateDuplicateLine($receiptNumber, $productCode, $lotNumber, $errors);

        return $errors;
    }

    public function importRows(array $rows, User $user, CsvImport $import): int
    {
        foreach ($rows as $index => $row) {
            if (! array_key_exists(self::EXPIRATION_TYPE, $row)) {
                throw new CsvImportFormatException('必須ヘッダー「期限種別」がありません。');
            }
            $errors = $this->expirationErrors($row);
            if ($errors !== []) {
                $error = $errors[0];
                throw new CsvImportFormatException('CSV'.($index + 2).'行目「'.$error['column'].'」: '.$error['message']);
            }
        }

        collect($rows)
            ->groupBy(fn (array $row): string => $this->value($row, self::RECEIPT_NUMBER))
            ->each(function (Collection $receiptRows, string $receiptNumber) use ($user): void {
                $first = $receiptRows->first();
                $supplier = $this->supplierMap()->get(strtoupper($this->value($first, self::SUPPLIER_CODE)));

                if ($supplier === null || PurchaseReceipt::where('receipt_number', $receiptNumber)->exists()) {
                    throw new CsvImportFormatException("入庫番号「{$receiptNumber}」の登録条件が検証時から変更されています。");
                }

                $receipt = new PurchaseReceipt([
                    'supplier_id' => $supplier->id,
                    'received_on' => $this->value($first, self::RECEIVED_ON),
                    'notes' => $this->nullable($this->value($first, self::NOTES)),
                ]);
                $receipt->receipt_number = $receiptNumber;
                $receipt->status = 'draft';
                $receipt->created_by = $user->id;
                $receipt->save();

                foreach ($receiptRows->values() as $index => $row) {
                    $product = $this->productMap()->get(strtoupper($this->value($row, self::PRODUCT_CODE)));
                    $setting = $product === null
                        ? null
                        : $this->purchaseSettingMap()->get($this->settingKey($supplier->id, $product->id));

                    if ($product === null || $setting === null) {
                        throw new CsvImportFormatException("入庫番号「{$receiptNumber}」の商品設定が検証時から変更されています。");
                    }

                    $quantity = $this->value($row, self::QUANTITY);
                    $unitPrice = $this->value($row, self::UNIT_PRICE);
                    $receipt->lines()->create([
                        'line_number' => $index + 1,
                        'product_id' => $product->id,
                        'product_supplier_id' => $setting->id,
                        'lot_number' => $this->value($row, self::LOT_NUMBER),
                        'manufactured_on' => $this->nullable($this->value($row, self::MANUFACTURED_ON)),
                        'expires_on' => $this->nullable($this->value($row, self::EXPIRES_ON)),
                        'expiration_type' => ReceiptExpiration::normalizeType($this->value($row, self::EXPIRATION_TYPE)),
                        'quantity' => $quantity,
                        'unit_purchase_price' => $unitPrice,
                        'purchase_amount' => bcmul($quantity, $unitPrice, 4),
                    ]);
                }
            });

        return count($rows);
    }

    public function templateRows(): array
    {
        return [];
    }

    /** @return array<string, true> */
    private function existingReceiptNumberMap(): array
    {
        return $this->existingReceiptNumbers ??= PurchaseReceipt::query()
            ->pluck('receipt_number')
            ->mapWithKeys(fn (string $receiptNumber): array => [mb_strtolower($receiptNumber) => true])
            ->all();
    }

    private function supplierMap(): Collection
    {
        return $this->suppliers ??= Supplier::query()
            ->where('status', 'active')
            ->get()
            ->keyBy(fn (Supplier $supplier): string => strtoupper($supplier->code));
    }

    private function productMap(): Collection
    {
        return $this->products ??= Product::query()
            ->whereIn('registration_status', ['provisional', 'confirmed'])
            ->with('unit:id,quantity_scale')
            ->get()
            ->keyBy(fn (Product $product): string => strtoupper($product->sku));
    }

    private function purchaseSettingMap(): Collection
    {
        return $this->purchaseSettings ??= ProductSupplier::query()
            ->where('status', 'active')
            ->get()
            ->keyBy(fn (ProductSupplier $setting): string => $this->settingKey($setting->supplier_id, $setting->product_id));
    }

    private function settingKey(int $supplierId, int $productId): string
    {
        return $supplierId.'|'.$productId;
    }

    /** @param list<array{column: string|null, message: string}> $errors */
    private function validateReceiptConsistency(
        string $receiptNumber,
        string $receivedOn,
        string $supplierCode,
        string $notes,
        array &$errors,
    ): void {
        if ($receiptNumber === '') {
            return;
        }

        $context = ['received_on' => $receivedOn, 'supplier_code' => $supplierCode, 'notes' => $notes];
        if (! isset($this->receiptContexts[$receiptNumber])) {
            $this->receiptContexts[$receiptNumber] = $context;

            return;
        }

        $first = $this->receiptContexts[$receiptNumber];
        if ($first['received_on'] !== $receivedOn) {
            $this->error($errors, self::RECEIVED_ON, '同じ入庫番号の入庫日は統一してください。');
        }
        if ($first['supplier_code'] !== $supplierCode) {
            $this->error($errors, self::SUPPLIER_CODE, '同じ入庫番号の仕入先コードは統一してください。');
        }
        if ($first['notes'] !== $notes) {
            $this->error($errors, self::NOTES, '同じ入庫番号の備考は統一してください。');
        }
    }

    /** @param list<array{column: string|null, message: string}> $errors */
    private function validateDuplicateLine(string $receiptNumber, string $productCode, string $lotNumber, array &$errors): void
    {
        if ($receiptNumber === '' || $productCode === '' || $lotNumber === '') {
            return;
        }

        $key = $receiptNumber.'|'.$productCode.'|'.mb_strtolower($lotNumber);
        if (isset($this->seenLines[$key])) {
            $this->error($errors, self::LOT_NUMBER, '同じ入庫番号内で販売商品とロット番号が重複しています。');
        }
        $this->seenLines[$key] = true;
    }

    /** @return list<array{column: string, message: string}> */
    private function expirationErrors(array $row): array
    {
        $values = [
            'manufactured_on' => $this->nullable($this->value($row, self::MANUFACTURED_ON)),
            'expiration_type' => ReceiptExpiration::normalizeType($this->value($row, self::EXPIRATION_TYPE)),
            'expires_on' => $this->nullable($this->value($row, self::EXPIRES_ON)),
        ];
        $validator = Validator::make($values, ReceiptExpiration::rules(), ReceiptExpiration::messages());
        $columns = ['manufactured_on' => self::MANUFACTURED_ON, 'expiration_type' => self::EXPIRATION_TYPE, 'expires_on' => self::EXPIRES_ON];
        $errors = [];
        foreach ($validator->errors()->messages() as $field => $messages) {
            foreach ($messages as $message) {
                $this->error($errors, $columns[$field], $message);
            }
        }
        if (ReceiptExpiration::isBeforeManufacture($values['manufactured_on'], $values['expires_on'])) {
            $this->error($errors, self::EXPIRES_ON, '期限日は製造日以降の日付を入力してください。');
        }

        return $errors;
    }

    private function isDate(string $value): bool
    {
        return ReceiptExpiration::isDate($value);
    }

    private function isPositiveDecimal(string $value): bool
    {
        return preg_match('/^\d{1,15}(\.\d{1,4})?$/', $value) === 1 && bccomp($value, '0', 4) === 1;
    }

    private function isNonNegativeDecimal(string $value): bool
    {
        return preg_match('/^\d{1,15}(\.\d{1,4})?$/', $value) === 1;
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
