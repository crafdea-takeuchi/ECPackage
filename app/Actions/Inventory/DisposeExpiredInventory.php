<?php

namespace App\Actions\Inventory;

use App\Models\InventoryLot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DisposeExpiredInventory
{
    public const REASON = '期限切れ廃棄';

    public const REASONS = ['期限切れ廃棄', '期限間近による廃棄', '品質上の判断による廃棄', 'その他'];

    /** @return array<string, array<int, mixed>> */
    public static function confirmationRules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::in(self::REASONS)],
            'notes' => ['nullable', 'required_if:reason,その他', 'string', 'max:2000'],
            'confirm_unexpired' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public static function confirmationMessages(): array
    {
        return [
            'reason.required' => '廃棄理由を選択してください。',
            'reason.in' => '廃棄理由を選び直してください。',
            'reason.string' => '廃棄理由を選び直してください。',
            'notes.required_if' => '廃棄理由が「その他」の場合は備考を入力してください。',
            'confirm_unexpired.boolean' => '期限前在庫の確認値が不正です。',
        ];
    }

    public const DEFAULT_NOTES = '在庫管理画面から一括廃棄';

    public function __construct(private readonly RecordStockOperation $recordStockOperation) {}

    public static function eligible(InventoryLot $lot): bool
    {
        return $lot->status === 'active'
            && bccomp((string) $lot->current_quantity, '0', 4) === 1
            && $lot->expires_on !== null;
    }

    /** @param array{lots: array<int, array{inventory_lot_id: int, product_id: int}>, occurred_on: string, reason: string, notes?: string|null, confirm_unexpired?: bool} $data
     * @return array{lot_count: int, totals: list<array{quantity: string, unit: string}>}
     */
    public function handle(array $data, User $actor): array
    {
        abort_unless($actor->isAdmin(), 403);
        Validator::make($data, self::confirmationRules(), self::confirmationMessages())->validate();

        return DB::transaction(function () use ($data, $actor): array {
            $ids = array_map(fn (array $line): int => (int) $line['inventory_lot_id'], $data['lots']);
            if ($ids === [] || count($ids) > 20 || count($ids) !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['lots' => '重複しない対象ロットを1〜20件選択してください。']);
            }
            $lots = InventoryLot::whereIn('id', $ids)->orderBy('id')->lockForUpdate()
                ->with('product.unit')->get();
            if ($lots->count() !== count($ids)) {
                throw ValidationException::withMessages(['lots' => '対象ロットが見つかりません。再検索して選び直してください。']);
            }
            $expiration = new InventoryExpiration;
            $products = collect($data['lots'])->keyBy(fn (array $line): int => (int) $line['inventory_lot_id']);
            $lines = [];
            $totals = [];
            foreach ($lots as $lot) {
                if (! self::eligible($lot) || ! $lot->product?->unit
                    || $lot->product_id !== (int) $products[$lot->id]['product_id']) {
                    throw ValidationException::withMessages(['lots' => 'ロット「'.$lot->lot_number.'」は廃棄できません。有効な期限付き在庫があるか、再検索して確認してください。']);
                }
                if ($lot->expires_on->format('Y-m-d') >= $expiration->today->toDateString()
                    && ! in_array($data['confirm_unexpired'] ?? false, [true, 1, '1'], true)) {
                    throw ValidationException::withMessages(['confirm_unexpired' => '期限前の在庫が含まれています。対象と期限日を再確認し、確認チェックを入れてください。']);
                }
                $quantity = (string) $lot->current_quantity;
                $lines[] = [
                    'inventory_lot_id' => $lot->id,
                    'quantity' => bcsub('0', $quantity, 4),
                    'reason' => $data['reason'],
                ];
                $unitId = $lot->product->unit->id;
                $totals[$unitId] ??= ['quantity' => '0', 'unit' => $lot->product->unit->name];
                $totals[$unitId]['quantity'] = bcadd($totals[$unitId]['quantity'], $quantity, 4);
            }

            $this->recordStockOperation->handle([
                'operation_type' => 'adjustment',
                'occurred_on' => $data['occurred_on'],
                'notes' => $data['notes'] ?? self::DEFAULT_NOTES,
                'lines' => $lines,
            ], $actor);

            return ['lot_count' => $lots->count(), 'totals' => array_values($totals)];
        }, 3);
    }
}
