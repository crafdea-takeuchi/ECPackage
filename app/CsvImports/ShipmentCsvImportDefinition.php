<?php

namespace App\CsvImports;

use App\Actions\Shipments\CancelShipment;
use App\Actions\Shipments\ConfirmShipment;
use App\Actions\Shipments\SyncShipmentLines;
use App\Contracts\CsvImportDefinition;
use App\Models\CsvImport;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Shipment;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use LogicException;

class ShipmentCsvImportDefinition implements CsvImportDefinition
{
    private const SHIPMENT_NUMBER = '発送番号';

    private const ORDER_NUMBER = '注文番号';

    private const STATUS = '発送ステータス';

    private const PRODUCT_CODE = '商品コード';

    private const QUANTITY = '発送数量';

    private const CARRIER = '配送会社';

    private const TRACKING_NUMBER = '追跡番号';

    private const SCHEDULED_ON = '発送予定日';

    private const SHIPPED_ON = '発送日';

    private const CANCELLATION_REASON = '取消理由';

    private const NOTES = '備考';

    private ?Collection $orders = null;

    private ?Collection $shipments = null;

    /** @var array<string, array<string, string>> */
    private array $shipmentContexts = [];

    /** @var array<string, true> */
    private array $seenProducts = [];

    /** @var array<int, string> */
    private array $projectedReserved = [];

    /** @var array<int, true> */
    private array $releasedShipmentIds = [];

    /** @var array<string, true> */
    private array $seenCancellationRows = [];

    public function __construct(
        private readonly SyncShipmentLines $syncShipmentLines,
        private readonly ConfirmShipment $confirmShipment,
        private readonly CancelShipment $cancelShipment,
    ) {}

    public function type(): string
    {
        return 'shipments';
    }

    /** @return list<string> */
    public function requiredHeaders(): array
    {
        return [
            self::SHIPMENT_NUMBER,
            self::ORDER_NUMBER,
            self::STATUS,
            self::PRODUCT_CODE,
            self::QUANTITY,
            self::CARRIER,
            self::TRACKING_NUMBER,
            self::SCHEDULED_ON,
            self::SHIPPED_ON,
            self::CANCELLATION_REASON,
            self::NOTES,
        ];
    }

    public function validateRow(array $row, int $rowNumber): array
    {
        $errors = [];
        $shipmentNumber = $this->value($row, self::SHIPMENT_NUMBER);
        $orderNumber = $this->value($row, self::ORDER_NUMBER);
        $status = $this->status($this->value($row, self::STATUS));
        $productCode = strtoupper($this->value($row, self::PRODUCT_CODE));
        $quantity = $this->value($row, self::QUANTITY);
        $carrier = $this->value($row, self::CARRIER);
        $trackingNumber = $this->value($row, self::TRACKING_NUMBER);
        $scheduledOn = $this->value($row, self::SCHEDULED_ON);
        $shippedOn = $this->value($row, self::SHIPPED_ON);
        $cancellationReason = $this->value($row, self::CANCELLATION_REASON);
        $notes = $this->value($row, self::NOTES);

        if ($shipmentNumber === '') {
            $this->error($errors, self::SHIPMENT_NUMBER, '発送番号を入力してください。');
        } elseif (mb_strlen($shipmentNumber) > 50) {
            $this->error($errors, self::SHIPMENT_NUMBER, '発送番号は50文字以内で入力してください。');
        }

        if ($orderNumber === '') {
            $this->error($errors, self::ORDER_NUMBER, '注文番号を入力してください。');
        }
        $order = $this->orderMap()->get(mb_strtolower($orderNumber));
        if ($orderNumber !== '' && $order === null) {
            $this->error($errors, self::ORDER_NUMBER, '有効な注文番号が見つかりません。');
        }

        if ($status === null) {
            $this->error($errors, self::STATUS, '発送ステータスは「発送準備中」「発送済み」「取消」のいずれかを入力してください。');
        }

        $shipment = $shipmentNumber === ''
            ? null
            : $this->shipmentMap()->get(mb_strtolower($shipmentNumber));
        if ($shipment !== null) {
            if ($shipment->status !== 'draft') {
                $this->error($errors, self::STATUS, '発送済みまたは取消済みの発送はCSVで変更できません。');
            }
            if ($order !== null && $shipment->order_id !== $order->id) {
                $this->error($errors, self::ORDER_NUMBER, '発送番号に登録されている注文番号と一致しません。');
            }
            if ($shipment->status === 'draft') {
                $this->releaseExistingShipment($shipment);
            }
        }

        $canProjectShipment = $shipment === null
            || ($shipment->status === 'draft' && ($order === null || $shipment->order_id === $order->id));

        $this->validateShipmentConsistency($shipmentNumber, [
            self::ORDER_NUMBER => $orderNumber,
            self::STATUS => $this->value($row, self::STATUS),
            self::CARRIER => $carrier,
            self::TRACKING_NUMBER => $trackingNumber,
            self::SCHEDULED_ON => $scheduledOn,
            self::SHIPPED_ON => $shippedOn,
            self::CANCELLATION_REASON => $cancellationReason,
            self::NOTES => $notes,
        ], $errors);

        if ($scheduledOn !== '' && ! $this->isDate($scheduledOn)) {
            $this->error($errors, self::SCHEDULED_ON, '発送予定日はYYYY-MM-DD形式で入力してください。');
        }
        if ($shippedOn !== '' && ! $this->isDate($shippedOn)) {
            $this->error($errors, self::SHIPPED_ON, '発送日はYYYY-MM-DD形式で入力してください。');
        }
        if (mb_strlen($carrier) > 100) {
            $this->error($errors, self::CARRIER, '配送会社は100文字以内で入力してください。');
        }
        if (mb_strlen($trackingNumber) > 100) {
            $this->error($errors, self::TRACKING_NUMBER, '追跡番号は100文字以内で入力してください。');
        }
        if (mb_strlen($notes) > 2000) {
            $this->error($errors, self::NOTES, '備考は2000文字以内で入力してください。');
        }

        if ($status === 'cancelled') {
            $this->validateCancellation($shipmentNumber, $shipment, $cancellationReason, $productCode, $quantity, $errors);

            return $errors;
        }

        if ($order !== null && ! in_array($order->status, ['confirmed', 'preparing'], true)) {
            $this->error($errors, self::ORDER_NUMBER, '発送準備ができる注文を指定してください。');
        }
        if ($status === 'shipped' && $carrier === '') {
            $this->error($errors, self::CARRIER, '発送済みにする場合は配送会社を入力してください。');
        }
        if ($status === 'shipped' && $shippedOn === '') {
            $this->error($errors, self::SHIPPED_ON, '発送済みにする場合は発送日を入力してください。');
        }
        if ($status === 'shipped' && $order?->backorders->contains(fn ($backorder): bool => bccomp((string) $backorder->remaining_quantity, '0', 4) === 1)) {
            $this->error($errors, self::STATUS, '取り寄せ商品が揃っていない注文は発送済みにできません。');
        }
        if ($cancellationReason !== '') {
            $this->error($errors, self::CANCELLATION_REASON, '取消以外では取消理由を空欄にしてください。');
        }

        if ($productCode === '') {
            $this->error($errors, self::PRODUCT_CODE, '商品コードを入力してください。');
        }
        $orderLine = $order === null || $productCode === ''
            ? null
            : $this->resolveOrderLine($order, $productCode, $errors);
        if ($orderLine !== null) {
            $key = mb_strtolower($shipmentNumber).'|'.$productCode;
            if (isset($this->seenProducts[$key])) {
                $this->error($errors, self::PRODUCT_CODE, '同じ発送番号内で商品コードが重複しています。');
            }
            $this->seenProducts[$key] = true;
        }

        if (! $this->isPositiveDecimal($quantity)) {
            $this->error($errors, self::QUANTITY, '発送数量は0より大きい数値を小数4桁以内で入力してください。');
        } elseif ($orderLine?->product?->unit !== null && $this->decimalPlaces($quantity) > $orderLine->product->unit->quantity_scale) {
            $scale = $orderLine->product->unit->quantity_scale;
            $message = $scale === 0
                ? 'この商品の発送数量は整数で入力してください。'
                : "この商品の発送数量は小数{$scale}桁以内で入力してください。";
            $this->error($errors, self::QUANTITY, $message);
        }

        if ($canProjectShipment && $orderLine !== null && $this->isPositiveDecimal($quantity)) {
            $this->projectShipmentQuantity($orderLine, $quantity, $errors);
        }

        return $errors;
    }

    public function importRows(array $rows, User $user, CsvImport $import): int
    {
        collect($rows)
            ->groupBy(fn (array $row): string => $this->value($row, self::SHIPMENT_NUMBER))
            ->each(function (Collection $shipmentRows, string $shipmentNumber) use ($user): void {
                $first = $shipmentRows->first();
                $targetStatus = $this->status($this->value($first, self::STATUS));
                $orderNumber = $this->value($first, self::ORDER_NUMBER);
                $order = Order::query()
                    ->where('order_number', $orderNumber)
                    ->lockForUpdate()
                    ->with(['lines.product.unit', 'backorders'])
                    ->first();
                $shipment = Shipment::query()
                    ->where('shipment_number', $shipmentNumber)
                    ->lockForUpdate()
                    ->first();

                if ($targetStatus === null || $order === null) {
                    throw new LogicException("発送番号「{$shipmentNumber}」の登録条件が検証時から変更されています。");
                }

                if ($targetStatus === 'cancelled') {
                    if ($shipment === null || $shipment->order_id !== $order->id) {
                        throw new LogicException("取消対象の発送番号「{$shipmentNumber}」が見つかりません。");
                    }
                    $this->cancelShipment->handle(
                        $shipment,
                        $user,
                        $this->value($first, self::CANCELLATION_REASON),
                    );

                    return;
                }

                $isExistingShipment = $shipment !== null;
                if ($shipment === null) {
                    $shipment = new Shipment(['order_id' => $order->id]);
                    $shipment->shipment_number = $shipmentNumber;
                    $shipment->status = 'draft';
                    $shipment->created_by = $user->id;
                } elseif ($shipment->status !== 'draft' || $shipment->order_id !== $order->id) {
                    throw new LogicException("発送番号「{$shipmentNumber}」はCSVで変更できません。");
                }

                $shipment->fill([
                    'carrier' => $this->nullable($this->value($first, self::CARRIER)),
                    'tracking_number' => $this->nullable($this->value($first, self::TRACKING_NUMBER)),
                    'scheduled_on' => $this->nullable($this->value($first, self::SCHEDULED_ON)),
                    'shipped_on' => $this->nullable($this->value($first, self::SHIPPED_ON)),
                    'notes' => $this->nullable($this->value($first, self::NOTES)),
                ]);
                if ($isExistingShipment) {
                    $shipment->lock_version++;
                }
                $shipment->save();

                $lineInputs = $shipmentRows->map(function (array $row) use ($order, $shipmentNumber): array {
                    $productCode = strtoupper($this->value($row, self::PRODUCT_CODE));
                    $matches = $order->lines->filter(
                        fn (OrderLine $line): bool => strtoupper($line->product_sku) === $productCode,
                    );
                    if ($matches->count() !== 1) {
                        throw new LogicException("発送番号「{$shipmentNumber}」の商品「{$productCode}」を特定できません。");
                    }

                    return [
                        'order_line_id' => $matches->first()->id,
                        'quantity' => $this->value($row, self::QUANTITY),
                    ];
                })->values()->all();

                $this->syncShipmentLines->handle($shipment, $lineInputs);

                if ($order->status === 'confirmed') {
                    $order->status = 'preparing';
                    $order->lock_version++;
                    $order->save();
                }

                if ($targetStatus === 'shipped') {
                    $this->confirmShipment->handle($shipment, $user);
                }
            });

        return count($rows);
    }

    public function templateRows(): array
    {
        return [];
    }

    private function orderMap(): Collection
    {
        return $this->orders ??= Order::query()
            ->with([
                'lines.product.unit:id,quantity_scale',
                'lines.shipmentLines.shipment:id,status',
                'backorders',
            ])
            ->get()
            ->keyBy(fn (Order $order): string => mb_strtolower($order->order_number));
    }

    private function shipmentMap(): Collection
    {
        return $this->shipments ??= Shipment::query()
            ->with('lines.orderLine.shipmentLines.shipment:id,status')
            ->get()
            ->keyBy(fn (Shipment $shipment): string => mb_strtolower($shipment->shipment_number));
    }

    /** @param list<array{column: string|null, message: string}> $errors */
    private function resolveOrderLine(Order $order, string $productCode, array &$errors): ?OrderLine
    {
        $matches = $order->lines->filter(
            fn (OrderLine $line): bool => strtoupper($line->product_sku) === $productCode,
        );

        if ($matches->isEmpty()) {
            $this->error($errors, self::PRODUCT_CODE, '注文に指定した商品コードがありません。');

            return null;
        }
        if ($matches->count() > 1) {
            $this->error($errors, self::PRODUCT_CODE, '注文内に同じ商品コードが複数あり、発送明細を特定できません。');

            return null;
        }

        return $matches->first();
    }

    /** @param array<string, string> $context
     * @param  list<array{column: string|null, message: string}>  $errors
     */
    private function validateShipmentConsistency(string $shipmentNumber, array $context, array &$errors): void
    {
        if ($shipmentNumber === '') {
            return;
        }

        $key = mb_strtolower($shipmentNumber);
        if (! isset($this->shipmentContexts[$key])) {
            $this->shipmentContexts[$key] = $context;

            return;
        }

        foreach ($context as $column => $value) {
            if ($this->shipmentContexts[$key][$column] !== $value) {
                $this->error($errors, $column, "同じ発送番号の{$column}は統一してください。");
            }
        }
    }

    /** @param list<array{column: string|null, message: string}> $errors */
    private function validateCancellation(
        string $shipmentNumber,
        ?Shipment $shipment,
        string $reason,
        string $productCode,
        string $quantity,
        array &$errors,
    ): void {
        if ($shipment === null) {
            $this->error($errors, self::SHIPMENT_NUMBER, '取消は登録済みの下書き発送に対して指定してください。');
        }
        if ($reason === '') {
            $this->error($errors, self::CANCELLATION_REASON, '取消する場合は取消理由を入力してください。');
        } elseif (mb_strlen($reason) > 500) {
            $this->error($errors, self::CANCELLATION_REASON, '取消理由は500文字以内で入力してください。');
        }
        if ($productCode !== '' || $quantity !== '') {
            $this->error($errors, self::PRODUCT_CODE, '取消する場合は商品コードと発送数量を空欄にしてください。');
        }

        $key = mb_strtolower($shipmentNumber);
        if (isset($this->seenCancellationRows[$key])) {
            $this->error($errors, self::SHIPMENT_NUMBER, '取消する発送番号はCSV内に1行だけ入力してください。');
        }
        $this->seenCancellationRows[$key] = true;
    }

    private function releaseExistingShipment(Shipment $shipment): void
    {
        if (isset($this->releasedShipmentIds[$shipment->id])) {
            return;
        }

        foreach ($shipment->lines as $line) {
            $reserved = $this->projectedReserved[$line->order_line_id]
                ?? $this->reservedQuantity($line->orderLine);
            $this->projectedReserved[$line->order_line_id] = bcsub($reserved, (string) $line->quantity, 4);
        }
        $this->releasedShipmentIds[$shipment->id] = true;
    }

    /** @param list<array{column: string|null, message: string}> $errors */
    private function projectShipmentQuantity(OrderLine $orderLine, string $quantity, array &$errors): void
    {
        $reserved = $this->projectedReserved[$orderLine->id] ?? $this->reservedQuantity($orderLine);
        $after = bcadd($reserved, $quantity, 4);

        if (bccomp($after, (string) $orderLine->quantity, 4) === 1) {
            $remaining = bcsub((string) $orderLine->quantity, $reserved, 4);
            $this->error($errors, self::QUANTITY, "CSV内の処理順では未発送数量（{$remaining} {$orderLine->unit_name}）を超えて発送できません。");

            return;
        }

        $this->projectedReserved[$orderLine->id] = $after;
    }

    private function reservedQuantity(OrderLine $orderLine): string
    {
        return (string) $orderLine->shipmentLines
            ->filter(fn ($line): bool => $line->shipment->status !== 'cancelled')
            ->sum('quantity');
    }

    private function status(string $value): ?string
    {
        return [
            '発送準備中' => 'draft',
            '下書き' => 'draft',
            'draft' => 'draft',
            '発送済み' => 'shipped',
            'shipped' => 'shipped',
            '取消' => 'cancelled',
            'キャンセル' => 'cancelled',
            'cancelled' => 'cancelled',
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
