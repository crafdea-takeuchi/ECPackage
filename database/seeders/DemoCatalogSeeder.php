<?php

namespace Database\Seeders;

use App\Actions\Orders\ConfirmOrder;
use App\Models\Category;
use App\Models\InventoryLot;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductSupplier;
use App\Models\PurchaseReceipt;
use App\Models\Shipment;
use App\Models\StockMovement;
use App\Models\StockOperation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoCatalogSeeder extends Seeder
{
    public function run(ConfirmOrder $confirmOrder): void
    {
        $this->call(UnitSeeder::class);

        $actor = User::where('role', 'admin')->whereNotNull('email_verified_at')->oldest('id')->first()
            ?? User::firstOrCreate(
                ['email' => 'test@example.com'],
                ['name' => 'Test User', 'password' => 'password', 'email_verified_at' => now()],
            );

        DB::transaction(function () use ($actor, $confirmOrder): void {
            $categories = $this->categories();
            $groups = $this->groups();
            $units = Unit::whereIn('code', ['piece', 'kg', 'set'])->pluck('id', 'code');

            $products = [
                'grape54' => $this->product($actor, [
                    'sku' => 'DEMO-GUMMY-GRAPE-54', 'name' => '【見本】果汁グミ ブドウ味 54g',
                    'group' => $groups['gummy'], 'unit_id' => $units['piece'], 'price' => '180', 'publication' => 'public',
                    'categories' => [$categories['sweets']->id, $categories['gummy']->id],
                    'values' => ['flavor' => 'ブドウ', 'volume' => '54g'],
                ]),
                'orange54' => $this->product($actor, [
                    'sku' => 'DEMO-GUMMY-ORANGE-54', 'name' => '【見本】果汁グミ ミカン味 54g',
                    'group' => $groups['gummy'], 'unit_id' => $units['piece'], 'price' => '180', 'publication' => 'public',
                    'categories' => [$categories['sweets']->id, $categories['gummy']->id],
                    'values' => ['flavor' => 'ミカン', 'volume' => '54g'],
                ]),
                'grape100' => $this->product($actor, [
                    'sku' => 'DEMO-GUMMY-GRAPE-100', 'name' => '【見本】果汁グミ ブドウ味 100g',
                    'group' => $groups['gummy'], 'unit_id' => $units['piece'], 'price' => '298', 'publication' => 'private',
                    'categories' => [$categories['sweets']->id, $categories['gummy']->id],
                    'values' => ['flavor' => 'ブドウ', 'volume' => '100g'],
                ]),
                'potatoDanshakuHokkaido' => $this->product($actor, [
                    'sku' => 'DEMO-POTATO-DAN-HKD-10', 'name' => '【見本】北海道産 男爵ジャガイモ 10kg箱',
                    'group' => $groups['potato'], 'unit_id' => $units['piece'], 'price' => '3500', 'publication' => 'public',
                    'categories' => [$categories['vegetables']->id, $categories['rootVegetables']->id, $categories['potato']->id],
                    'values' => ['variety' => '男爵', 'origin' => '北海道', 'package' => '10kg箱'],
                ]),
                'potatoMayHokkaido' => $this->product($actor, [
                    'sku' => 'DEMO-POTATO-MAY-HKD-10', 'name' => '【見本】北海道産 メークイン 10kg箱',
                    'group' => $groups['potato'], 'unit_id' => $units['piece'], 'price' => '3800', 'publication' => 'public',
                    'categories' => [$categories['vegetables']->id, $categories['rootVegetables']->id, $categories['potato']->id],
                    'values' => ['variety' => 'メークイン', 'origin' => '北海道', 'package' => '10kg箱'],
                ]),
                'potatoDanshakuNagasaki' => $this->product($actor, [
                    'sku' => 'DEMO-POTATO-DAN-NGS-10', 'name' => '【見本】長崎県産 男爵ジャガイモ 10kg箱',
                    'group' => $groups['potato'], 'unit_id' => $units['piece'], 'price' => '3300', 'publication' => 'private',
                    'categories' => [$categories['vegetables']->id, $categories['rootVegetables']->id, $categories['potato']->id],
                    'values' => ['variety' => '男爵', 'origin' => '長崎県', 'package' => '10kg箱'],
                ]),
                'juice' => $this->product($actor, [
                    'sku' => 'DEMO-JUICE-APPLE-1L', 'name' => '【見本】りんごジュース 1L',
                    'group' => null, 'unit_id' => $units['piece'], 'price' => '680', 'publication' => 'public',
                    'categories' => [$categories['drinks']->id], 'values' => [],
                ]),
                'provisionalLemon' => $this->product($actor, [
                    'sku' => 'DEMO-GUMMY-LEMON', 'name' => '【見本・仮登録】季節限定レモングミ',
                    'group' => $groups['gummy'], 'unit_id' => $units['piece'], 'price' => null, 'publication' => 'private',
                    'registration' => 'provisional', 'categories' => [$categories['sweets']->id, $categories['gummy']->id], 'values' => [],
                ]),
            ];

            $products['gummyVarietySet'] = $this->bundleProduct($actor, [
                'sku' => 'DEMO-SET-GUMMY-VARIETY', 'name' => '【見本】果汁グミ食べ比べセット',
                'unit_id' => $units['set'], 'price' => '330', 'publication' => 'public', 'shortage_policy' => 'reject',
                'categories' => [$categories['sweets']->id, $categories['gummy']->id],
            ], [
                ['product' => $products['grape54'], 'quantity' => '1'],
                ['product' => $products['orange54'], 'quantity' => '1'],
            ]);
            $products['grapeTripleSet'] = $this->bundleProduct($actor, [
                'sku' => 'DEMO-SET-GUMMY-GRAPE-3', 'name' => '【見本】果汁グミ ブドウ味3袋セット',
                'unit_id' => $units['set'], 'price' => '500', 'publication' => 'public', 'shortage_policy' => 'backorder',
                'categories' => [$categories['sweets']->id, $categories['gummy']->id],
            ], [
                ['product' => $products['grape54'], 'quantity' => '3'],
            ]);

            $suppliers = [
                'sweets' => $this->supplier('DEMO-SUP-SWEETS', '【見本】お菓子卸センター', '山田 花子', 'sweets@example.test', '03-0000-1000', '東京都千代田区1-1', '菓子・飲料の見本仕入先'),
                'hokkaido' => $this->supplier('DEMO-SUP-HOKKAIDO', '【見本】北海道青果', '佐藤 一郎', 'hokkaido@example.test', '011-000-2000', '北海道札幌市中央区2-2', '北海道産野菜の見本仕入先'),
                'national' => $this->supplier('DEMO-SUP-NATIONAL', '【見本】全国農産流通', '鈴木 次郎', 'national@example.test', '06-0000-3000', '大阪府大阪市北区3-3', '複数産地を扱う見本仕入先'),
            ];

            $this->purchaseSetting($suppliers['sweets'], $products['grape54'], 'SW-GRAPE-54', '105', '10', 2, true);
            $this->purchaseSetting($suppliers['sweets'], $products['orange54'], 'SW-ORANGE-54', '108', '10', 2, true);
            $this->purchaseSetting($suppliers['sweets'], $products['grape100'], 'SW-GRAPE-100', '175', '5', 3, true);
            $this->purchaseSetting($suppliers['sweets'], $products['juice'], 'SW-APPLE-1L', '390', '6', 4, true);
            $this->purchaseSetting($suppliers['hokkaido'], $products['potatoDanshakuHokkaido'], 'HK-DAN-10', '1800', '5', 3, true);
            $this->purchaseSetting($suppliers['hokkaido'], $products['potatoMayHokkaido'], 'HK-MAY-10', '1950', '5', 3, true);
            $this->purchaseSetting($suppliers['national'], $products['potatoDanshakuHokkaido'], 'ZN-HKD-DAN-10', '1880', '3', 5, false);
            $this->purchaseSetting($suppliers['national'], $products['potatoMayHokkaido'], 'ZN-HKD-MAY-10', '2020', '3', 5, false);
            $this->purchaseSetting($suppliers['national'], $products['potatoDanshakuNagasaki'], 'ZN-NGS-DAN-10', '1720', '3', 4, true);

            $this->inventorySamples($actor, $products, $suppliers);
            $this->stockOperationSamples($actor);
            $this->orderSamples($actor, $confirmOrder, $products);
            $this->shipmentSamples($actor);
        }, 3);
    }

    /** @return array<string, Category> */
    private function categories(): array
    {
        $root = $this->category('見本商品', null, 900);
        $sweets = $this->category('お菓子', $root, 10);
        $vegetables = $this->category('野菜', $root, 20);
        $rootVegetables = $this->category('根菜', $vegetables, 10);

        return [
            'root' => $root,
            'sweets' => $sweets,
            'gummy' => $this->category('グミ', $sweets, 10),
            'vegetables' => $vegetables,
            'rootVegetables' => $rootVegetables,
            'potato' => $this->category('ジャガイモ', $rootVegetables, 10),
            'drinks' => $this->category('飲料', $root, 30),
        ];
    }

    private function category(string $name, ?Category $parent, int $sortOrder): Category
    {
        return Category::updateOrCreate(
            ['name' => $name, 'parent_id' => $parent?->id],
            ['sort_order' => $sortOrder, 'status' => 'active'],
        );
    }

    /** @return array<string, ProductGroup> */
    private function groups(): array
    {
        $gummy = ProductGroup::updateOrCreate(
            ['name' => '【見本】果汁グミ'],
            ['description' => '味と内容量が異なる販売商品の見本です。', 'status' => 'active'],
        );
        $this->definition($gummy, 'flavor', '味', ['ブドウ', 'ミカン', 'レモン'], 10);
        $this->definition($gummy, 'volume', '内容量', ['54g', '100g'], 20);

        $potato = ProductGroup::updateOrCreate(
            ['name' => '【見本】ジャガイモ'],
            ['description' => '品種・産地・荷姿が異なる販売商品の見本です。', 'status' => 'active'],
        );
        $this->definition($potato, 'variety', '品種', ['男爵', 'メークイン'], 10);
        $this->definition($potato, 'origin', '産地', ['北海道', '長崎県'], 20);
        $this->definition($potato, 'package', '荷姿', ['10kg箱'], 30);

        return ['gummy' => $gummy->fresh('definitions'), 'potato' => $potato->fresh('definitions')];
    }

    private function definition(ProductGroup $group, string $code, string $name, array $options, int $sortOrder): void
    {
        $group->definitions()->updateOrCreate(
            ['code' => $code],
            ['name' => $name, 'input_type' => 'select', 'options' => $options, 'is_required' => true, 'sort_order' => $sortOrder, 'status' => 'active'],
        );
    }

    /** @param array<string, mixed> $data */
    private function product(User $actor, array $data): Product
    {
        $product = Product::firstOrNew(['sku' => $data['sku']]);
        $product->name = $data['name'];
        $product->kind = 'single';
        $product->product_group_id = $data['group']?->id;
        $product->unit_id = $data['unit_id'];
        $product->registration_status = $data['registration'] ?? 'confirmed';
        $product->publication_status = $data['publication'];
        $product->default_sale_price = $data['price'];
        $product->created_by ??= $actor->id;
        if ($product->registration_status === 'confirmed') {
            $product->confirmed_by ??= $actor->id;
            $product->confirmed_at ??= now();
        } else {
            $product->confirmed_by = null;
            $product->confirmed_at = null;
        }
        $product->save();
        $product->categories()->sync($data['categories']);

        if ($data['group']) {
            $definitions = $data['group']->definitions->keyBy('code');
            foreach ($data['values'] as $code => $value) {
                $product->attributeValues()->updateOrCreate(
                    ['product_attribute_definition_id' => $definitions[$code]->id],
                    ['value' => $value],
                );
            }
        }

        return $product;
    }

    /** @param array<string, mixed> $data @param array<int, array{product: Product, quantity: string}> $components */
    private function bundleProduct(User $actor, array $data, array $components): Product
    {
        $product = Product::firstOrNew(['sku' => $data['sku']]);
        $product->fill([
            'name' => $data['name'],
            'kind' => 'bundle',
            'product_group_id' => null,
            'unit_id' => $data['unit_id'],
            'registration_status' => 'confirmed',
            'publication_status' => $data['publication'],
            'shortage_policy' => $data['shortage_policy'],
            'default_sale_price' => $data['price'],
        ]);
        $product->created_by ??= $actor->id;
        $product->confirmed_by ??= $actor->id;
        $product->confirmed_at ??= now();
        $product->save();
        $product->categories()->sync($data['categories']);
        $product->attributeValues()->delete();

        $componentIds = [];
        foreach ($components as $index => $component) {
            $componentIds[] = $component['product']->id;
            $product->bundleComponents()->updateOrCreate(
                ['component_product_id' => $component['product']->id],
                ['quantity' => $component['quantity'], 'sort_order' => $index + 1],
            );
        }
        $product->bundleComponents()->whereNotIn('component_product_id', $componentIds)->delete();

        return $product;
    }

    private function supplier(string $code, string $name, string $contact, string $email, string $phone, string $address, string $notes): Supplier
    {
        return Supplier::updateOrCreate(
            ['code' => $code],
            ['name' => $name, 'contact_name' => $contact, 'email' => $email, 'phone' => $phone, 'address' => $address, 'notes' => $notes, 'status' => 'active'],
        );
    }

    /** @param array<string, Product> $products */
    private function orderSamples(User $actor, ConfirmOrder $confirmOrder, array $products): void
    {
        $customer = User::firstOrCreate(
            ['email' => 'demo-customer@example.test'],
            ['name' => '【見本】山田 顧客', 'password' => str()->random(40), 'email_verified_at' => now()],
        );

        $confirmed = $this->order($actor, $customer, 'DEMO-O-202610-001', '2026-10-20', '見本：確定済みの代理注文');
        $this->orderLine($confirmed, $products['grape54'], '4', '180', 1);
        if ($confirmed->status === 'draft') {
            $confirmOrder->handle($confirmed, $actor);
        }

        $draft = $this->order($actor, $customer, 'DEMO-O-202610-002', '2026-10-21', '見本：確認中の下書き注文');
        $this->orderLine($draft, $products['orange54'], '3', '180', 1);

        $bundleOrder = $this->order($actor, $customer, 'DEMO-O-202610-003', '2026-10-22', '見本：セット商品の確定注文');
        $this->orderLine($bundleOrder, $products['gummyVarietySet'], '2', '330', 1);
        if ($bundleOrder->status === 'draft') {
            $confirmOrder->handle($bundleOrder, $actor);
        }
    }

    private function shipmentSamples(User $actor): void
    {
        $draftOrder = Order::where('order_number', 'DEMO-O-202610-001')->firstOrFail();
        $draft = Shipment::firstOrCreate(
            ['shipment_number' => 'DEMO-SH-202610-001'],
            [
                'order_id' => $draftOrder->id,
                'status' => 'draft',
                'carrier' => 'ヤマト運輸',
                'scheduled_on' => '2026-10-24',
                'notes' => '見本：発送準備中の伝票',
                'created_by' => $actor->id,
            ],
        );
        $draft->lines()->firstOrCreate(
            ['order_line_id' => $draftOrder->lines()->firstOrFail()->id],
            ['quantity' => '4'],
        );
        if ($draft->wasRecentlyCreated && $draftOrder->status === 'confirmed') {
            $draftOrder->update(['status' => 'preparing', 'lock_version' => $draftOrder->lock_version + 1]);
        }

        $shippedOrder = Order::where('order_number', 'DEMO-O-202610-003')->firstOrFail();
        $shipped = Shipment::firstOrCreate(
            ['shipment_number' => 'DEMO-SH-202610-002'],
            [
                'order_id' => $shippedOrder->id,
                'status' => 'shipped',
                'carrier' => '日本郵便',
                'tracking_number' => 'DEMO-TRACK-202610-002',
                'scheduled_on' => '2026-10-23',
                'shipped_on' => '2026-10-23',
                'notes' => '見本：セット商品の発送済み伝票',
                'created_by' => $actor->id,
                'shipped_by' => $actor->id,
                'shipped_at' => now(),
            ],
        );
        $shipped->lines()->firstOrCreate(
            ['order_line_id' => $shippedOrder->lines()->firstOrFail()->id],
            ['quantity' => '2'],
        );
        if ($shipped->wasRecentlyCreated && in_array($shippedOrder->status, ['confirmed', 'preparing'], true)) {
            $shippedOrder->update(['status' => 'shipped', 'lock_version' => $shippedOrder->lock_version + 1]);
        }
    }

    private function order(User $actor, User $customer, string $number, string $date, string $notes): Order
    {
        return Order::firstOrCreate(
            ['order_number' => $number],
            [
                'customer_id' => $customer->id,
                'order_source' => 'admin',
                'ordered_on' => $date,
                'status' => 'draft',
                'subtotal' => 0,
                'total' => 0,
                'notes' => $notes,
                'created_by' => $actor->id,
            ],
        );
    }

    private function orderLine(Order $order, Product $product, string $quantity, string $price, int $lineNumber): void
    {
        if ($order->status !== 'draft') {
            return;
        }
        $amount = bcmul($quantity, $price, 4);
        $order->lines()->firstOrCreate(
            ['line_number' => $lineNumber],
            [
                'product_id' => $product->id,
                'product_sku' => $product->sku,
                'product_name' => $product->name,
                'unit_name' => $product->unit->name,
                'quantity' => $quantity,
                'unit_sale_price' => $price,
                'line_amount' => $amount,
            ],
        );
        $order->update(['subtotal' => $amount, 'total' => $amount]);
    }

    private function stockOperationSamples(User $actor): void
    {
        $outbound = $this->stockOperation($actor, 'DEMO-SO-202610-001', 'outbound', '2026-10-19', '見本：受注出荷による出庫');
        $this->stockOperationLine($actor, $outbound, 'DEMO-GRAPE-202610', '-5', '見本注文 DEMO-ORDER-001');

        $adjustment = $this->stockOperation($actor, 'DEMO-SO-202610-002', 'adjustment', '2026-10-20', '見本：棚卸による在庫調整');
        $this->stockOperationLine($actor, $adjustment, 'DEMO-ORANGE-202610', '-2', '破損分を調整');
    }

    private function stockOperation(User $actor, string $number, string $type, string $date, string $notes): StockOperation
    {
        return StockOperation::firstOrCreate(
            ['operation_number' => $number],
            ['operation_type' => $type, 'occurred_on' => $date, 'notes' => $notes, 'created_by' => $actor->id],
        );
    }

    private function stockOperationLine(User $actor, StockOperation $operation, string $lotNumber, string $delta, string $reason): void
    {
        $lot = InventoryLot::where('lot_number', $lotNumber)->lockForUpdate()->firstOrFail();
        $before = (string) $lot->current_quantity;
        $after = bcadd($before, $delta, 4);
        $line = $operation->lines()->firstOrCreate(
            ['line_number' => 1],
            [
                'inventory_lot_id' => $lot->id,
                'product_id' => $lot->product_id,
                'quantity_delta' => $delta,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'unit_cost' => $lot->unit_purchase_price,
                'reason' => $reason,
            ],
        );
        if (! $line->wasRecentlyCreated) {
            return;
        }

        $lot->update(['current_quantity' => $after, 'status' => bccomp($after, '0', 4) === 0 ? 'depleted' : 'active']);
        StockMovement::create([
            'product_id' => $lot->product_id,
            'inventory_lot_id' => $lot->id,
            'movement_type' => $operation->operation_type,
            'occurred_on' => $operation->occurred_on,
            'quantity_delta' => $delta,
            'unit_cost' => $lot->unit_purchase_price,
            'source_type' => 'stock_operation_line',
            'source_id' => $line->id,
            'notes' => $reason,
            'created_by' => $actor->id,
        ]);
    }

    /** @param array<string, Product> $products @param array<string, Supplier> $suppliers */
    private function inventorySamples(User $actor, array $products, array $suppliers): void
    {
        $sweetReceipt = $this->receipt($actor, 'DEMO-R-202610-001', $suppliers['sweets'], '2026-10-15', 'posted', '見本：お菓子の確定入庫');
        $this->receiptLine($actor, $sweetReceipt, $products['grape54'], 'DEMO-GRAPE-202610', '120', '105', 1, '2026-09-25', '2027-03-31');
        $this->receiptLine($actor, $sweetReceipt, $products['orange54'], 'DEMO-ORANGE-202610', '80', '108', 2, '2026-09-26', '2027-03-31');

        $vegetableReceipt = $this->receipt($actor, 'DEMO-R-202610-002', $suppliers['hokkaido'], '2026-10-18', 'posted', '見本：北海道野菜の確定入庫');
        $this->receiptLine($actor, $vegetableReceipt, $products['potatoDanshakuHokkaido'], 'DEMO-POTATO-202610', '25', '1800', 1, null, '2026-11-30');

        $draftReceipt = $this->receipt($actor, 'DEMO-R-202610-003', $suppliers['national'], '2026-10-21', 'draft', '見本：確認中の下書き入庫');
        $this->receiptLine($actor, $draftReceipt, $products['potatoDanshakuNagasaki'], 'DEMO-DRAFT-202610', '10', '1720', 1);
    }

    private function receipt(User $actor, string $number, Supplier $supplier, string $date, string $status, string $notes): PurchaseReceipt
    {
        return PurchaseReceipt::firstOrCreate(
            ['receipt_number' => $number],
            [
                'supplier_id' => $supplier->id,
                'received_on' => $date,
                'status' => $status,
                'notes' => $notes,
                'created_by' => $actor->id,
                'posted_by' => $status === 'posted' ? $actor->id : null,
                'posted_at' => $status === 'posted' ? now() : null,
            ],
        );
    }

    private function receiptLine(
        User $actor,
        PurchaseReceipt $receipt,
        Product $product,
        string $lotNumber,
        string $quantity,
        string $unitPrice,
        int $lineNumber,
        ?string $manufacturedOn = null,
        ?string $expiresOn = null,
    ): void {
        $setting = ProductSupplier::where('supplier_id', $receipt->supplier_id)->where('product_id', $product->id)->firstOrFail();
        $line = $receipt->lines()->firstOrCreate(
            ['line_number' => $lineNumber],
            [
                'product_id' => $product->id,
                'product_supplier_id' => $setting->id,
                'lot_number' => $lotNumber,
                'manufactured_on' => $manufacturedOn,
                'expires_on' => $expiresOn,
                'quantity' => $quantity,
                'unit_purchase_price' => $unitPrice,
                'purchase_amount' => bcmul($quantity, $unitPrice, 4),
            ],
        );

        if ($receipt->status !== 'posted') {
            return;
        }

        $lot = InventoryLot::firstOrCreate(
            ['purchase_receipt_line_id' => $line->id],
            [
                'product_id' => $product->id,
                'supplier_id' => $receipt->supplier_id,
                'lot_number' => $lotNumber,
                'received_on' => $receipt->received_on,
                'manufactured_on' => $manufacturedOn,
                'expires_on' => $expiresOn,
                'initial_quantity' => $quantity,
                'current_quantity' => $quantity,
                'unit_purchase_price' => $unitPrice,
                'status' => 'active',
            ],
        );
        StockMovement::firstOrCreate(
            ['source_type' => 'purchase_receipt_line', 'source_id' => $line->id],
            [
                'product_id' => $product->id,
                'inventory_lot_id' => $lot->id,
                'movement_type' => 'inbound',
                'occurred_on' => $receipt->received_on,
                'quantity_delta' => $quantity,
                'unit_cost' => $unitPrice,
                'notes' => '仕入入庫 '.$receipt->receipt_number,
                'created_by' => $actor->id,
            ],
        );
    }

    private function purchaseSetting(Supplier $supplier, Product $product, string $supplierCode, string $price, string $minimum, int $leadTime, bool $primary): void
    {
        ProductSupplier::updateOrCreate(
            ['supplier_id' => $supplier->id, 'product_id' => $product->id],
            ['supplier_product_code' => $supplierCode, 'default_purchase_price' => $price, 'minimum_order_quantity' => $minimum, 'lead_time_days' => $leadTime, 'is_primary' => $primary, 'status' => 'active'],
        );
    }
}
