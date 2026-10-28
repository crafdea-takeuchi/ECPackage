<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptLine;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(UnitSeeder::class);
        $actor = User::query()->where('role', 'admin')->whereNotNull('email_verified_at')->oldest('id')->first();

        if (! $actor) {
            throw new RuntimeException('ProductCatalogSeeder requires a verified administrator. Run DatabaseSeeder first.');
        }

        DB::transaction(function () use ($actor): void {
            $categories = $this->categories();
            $units = Unit::query()->whereIn('code', ['kg', 'piece'])->pluck('id', 'code');
            $products = [
                'nagasaki-kogane' => $this->product($actor, ['sku' => 'P-01M60RDMQMSM93NWDVH9JYMR8D', 'name' => 'ジャガイモ(長崎こがね)5kg', 'unit_id' => $units['kg'], 'price' => '5000.0000', 'category_id' => $categories['nagasaki-kogane']->id]),
                'red-moon' => $this->product($actor, ['sku' => 'P-01M60RQYSHAECYSMA5WAQMP2PZ', 'name' => 'ジャガイモ(レッドムーン)5kg', 'unit_id' => $units['kg'], 'price' => '5000.0000', 'category_id' => $categories['red-moon']->id]),
                'frying-pan' => $this->product($actor, ['sku' => 'P-01M60RZ2S56VTP2C0A877WT8FM', 'name' => 'フライパン', 'unit_id' => $units['piece'], 'price' => '10000.0000', 'category_id' => $categories['frying-pan']->id]),
            ];

            $supplier = Supplier::query()->updateOrCreate(
                ['code' => 'S-01M60S4V5JE5XMBAHFJVK3059F'],
                ['name' => 'demo', 'contact_name' => 'demo', 'email' => 'demo@demo', 'phone' => null, 'postal_code' => null, 'address' => null, 'notes' => null, 'status' => 'active'],
            );

            $this->inventory($actor, $supplier, $products['red-moon'], ['minimum' => '50.0000', 'lead_time' => 2, 'receipt' => 'R-01M60SCKPCBZ20RS7P4QD3M8DP', 'lot' => 'ptt1-20261028-01', 'quantity' => '50.0000', 'cost' => '200000.0000']);
            $this->inventory($actor, $supplier, $products['nagasaki-kogane'], ['minimum' => '50.0000', 'lead_time' => null, 'receipt' => 'R-01M60SEQSDMFG3FRG23CEAHYQ6', 'lot' => 'ptt1-20261028-02', 'quantity' => '100.0000', 'cost' => '400000.0000']);
            $this->inventory($actor, $supplier, $products['frying-pan'], ['minimum' => '10.0000', 'lead_time' => null, 'receipt' => 'R-01M60SH1VYJYRQ93XQGBX55XGR', 'lot' => 'frp1-20261028-01', 'quantity' => '10.0000', 'cost' => '5000.0000']);

        }, 3);
    }

    /** @return array<string, Category> */
    private function categories(): array
    {
        $food = $this->category('食品', null, 0);
        $vegetables = $this->category('野菜', $food, 10);
        $potatoes = $this->category('ジャガイモ', $vegetables, 20);
        $kitchen = $this->category('キッチン用品', null, 0);
        $cookware = $this->category('調理器具', $kitchen, 10);

        return [
            'nagasaki-kogane' => $this->category('長崎こがね', $potatoes, 30),
            'red-moon' => $this->category('レッドムーン', $potatoes, 30),
            'frying-pan' => $this->category('フライパン', $cookware, 30),
        ];
    }

    private function category(string $name, ?Category $parent, int $sortOrder): Category
    {
        return Category::query()->updateOrCreate(
            ['name' => $name, 'parent_id' => $parent?->id],
            ['sort_order' => $sortOrder, 'status' => 'active'],
        );
    }

    /** @param array{sku: string, name: string, unit_id: int, price: string, category_id: int} $data */
    private function product(User $actor, array $data): Product
    {
        $product = Product::query()->firstOrNew(['sku' => $data['sku']]);
        $product->fill([
            'name' => $data['name'],
            'kind' => 'single',
            'product_group_id' => null,
            'unit_id' => $data['unit_id'],
            'registration_status' => 'confirmed',
            'publication_status' => 'public',
            'shortage_policy' => 'reject',
            'default_sale_price' => $data['price'],
        ]);
        $product->created_by ??= $actor->id;
        $product->confirmed_by = $actor->id;
        $product->confirmed_at ??= now();
        $product->save();
        $product->categories()->sync([$data['category_id']]);

        return $product;
    }

    /** @param array{minimum: string, lead_time: ?int, receipt: string, lot: string, quantity: string, cost: string} $data */
    private function inventory(User $actor, Supplier $supplier, Product $product, array $data): void
    {
        $setting = ProductSupplier::query()->updateOrCreate(
            ['supplier_id' => $supplier->id, 'product_id' => $product->id],
            ['supplier_product_code' => null, 'default_purchase_price' => null, 'minimum_order_quantity' => $data['minimum'], 'lead_time_days' => $data['lead_time'], 'is_primary' => false, 'status' => 'active'],
        );

        $receipt = PurchaseReceipt::query()->firstOrNew(['receipt_number' => $data['receipt']]);
        $receipt->forceFill([
            'supplier_id' => $supplier->id,
            'received_on' => '2026-10-28',
            'status' => 'posted',
            'notes' => null,
            'created_by' => $actor->id,
            'posted_by' => $actor->id,
            'posted_at' => $receipt->posted_at ?? now(),
        ])->save();

        $line = PurchaseReceiptLine::query()->firstOrCreate(
            ['purchase_receipt_id' => $receipt->id, 'line_number' => 1],
            ['product_id' => $product->id, 'product_supplier_id' => $setting->id, 'lot_number' => $data['lot'], 'manufactured_on' => null, 'expires_on' => null, 'quantity' => $data['quantity'], 'unit_purchase_price' => $data['cost'], 'purchase_amount' => bcmul($data['quantity'], $data['cost'], 4)],
        );

        $lot = InventoryLot::query()->firstOrCreate(
            ['purchase_receipt_line_id' => $line->id],
            ['product_id' => $product->id, 'supplier_id' => $supplier->id, 'lot_number' => $data['lot'], 'received_on' => '2026-10-28', 'manufactured_on' => null, 'expires_on' => null, 'initial_quantity' => $data['quantity'], 'current_quantity' => $data['quantity'], 'unit_purchase_price' => $data['cost'], 'status' => 'active'],
        );

        StockMovement::query()->firstOrCreate(
            ['source_type' => 'purchase_receipt_line', 'source_id' => $line->id],
            ['product_id' => $product->id, 'inventory_lot_id' => $lot->id, 'movement_type' => 'inbound', 'occurred_on' => '2026-10-28', 'quantity_delta' => $data['quantity'], 'unit_cost' => $data['cost'], 'notes' => '仕入入庫 '.$receipt->receipt_number, 'created_by' => $actor->id],
        );
    }
}
