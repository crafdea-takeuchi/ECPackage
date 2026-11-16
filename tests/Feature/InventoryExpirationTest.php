<?php

namespace Tests\Feature;

use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptLine;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class InventoryExpirationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, InventoryLot> */
    private function lots(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 15:00:00', 'UTC'));
        $this->actingAs(User::factory()->admin()->create());
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create(['sku' => 'EXPIRATION-TEST']);
        $setting = ProductSupplier::factory()->create(['supplier_id' => $supplier->id, 'product_id' => $product->id]);
        $receipt = PurchaseReceipt::factory()->create(['supplier_id' => $supplier->id]);
        $lots = [];
        foreach ([
            'expired' => ['use_by', '2026-11-13'],
            'today' => ['use_by', '2026-11-16'],
            'tomorrow' => ['best_before', '2026-11-17'],
            'near' => ['best_before', '2026-12-16'],
            'valid' => ['best_before', '2026-12-17'],
            'none' => [null, null],
            'legacy' => [null, '2027-01-01'],
        ] as $key => [$type, $date]) {
            $line = PurchaseReceiptLine::factory()->create([
                'purchase_receipt_id' => $receipt->id, 'product_id' => $product->id,
                'product_supplier_id' => $setting->id, 'line_number' => count($lots) + 1,
                'expiration_type' => $type, 'expires_on' => $date, 'lot_number' => $key,
            ]);
            $lots[$key] = InventoryLot::factory()->create([
                'purchase_receipt_line_id' => $line->id, 'product_id' => $product->id,
                'supplier_id' => $supplier->id, 'received_on' => '2026-11-01',
                'expiration_type' => $type, 'expires_on' => $date, 'lot_number' => $key,
            ]);
        }

        return $lots;
    }

    #[TestWith(['expired', '消費期限', -3, '3日超過', 'expired', '期限切れ'])]
    #[TestWith(['today', '消費期限', 0, '本日期限', 'today', '本日期限'])]
    #[TestWith(['tomorrow', '賞味期限', 1, '残り1日', 'near', '期限間近'])]
    #[TestWith(['near', '賞味期限', 30, '残り30日', 'near', '期限間近'])]
    #[TestWith(['valid', '賞味期限', 31, '残り31日', 'valid', '期限内'])]
    #[TestWith(['none', '期限なし', null, '—', 'none', '期限なし'])]
    #[TestWith(['legacy', '種別未設定', 46, '残り46日', 'valid', '期限内'])]
    public function test_displays_expiration_from_lot(string $key, string $label, ?int $days, string $remaining, string $status, string $statusLabel): void
    {
        $lots = $this->lots();
        $this->get('/admin/inventory-lots?q='.$key)->assertInertia(fn (Assert $page) => $page
            ->where('lots.total', 1)->where('lots.data.0.id', $lots[$key]->id)
            ->where('lots.data.0.expiration', ['type_label' => $label, 'days' => $days, 'remaining_label' => $remaining, 'status' => $status, 'status_label' => $statusLabel])
            ->where('expirationSettings.today', '2026-11-16'));
    }

    public function test_japan_midnight_changes_date_even_when_utc_date_is_unchanged(): void
    {
        $this->lots();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 14:59:59', 'UTC'));
        $this->get('/admin/inventory-lots?q=today')->assertInertia(fn (Assert $page) => $page
            ->where('expirationSettings.today', '2026-11-15')->where('lots.data.0.expiration.days', 1));
        $this->travelTo(CarbonImmutable::parse('2026-11-15 15:00:00', 'UTC'));
        $this->get('/admin/inventory-lots?q=today')->assertInertia(fn (Assert $page) => $page
            ->where('expirationSettings.today', '2026-11-16')->where('lots.data.0.expiration.days', 0));
    }

    #[TestWith(['expiration_type=use_by', ['expired', 'today']])]
    #[TestWith(['expiration_type=best_before', ['tomorrow', 'near', 'valid']])]
    #[TestWith(['expiration_type=none', ['none']])]
    #[TestWith(['expiration_status=expired', ['expired']])]
    #[TestWith(['expiration_status=today', ['today']])]
    #[TestWith(['expiration_status=near', ['tomorrow', 'near']])]
    #[TestWith(['expiration_status=valid', ['valid', 'legacy']])]
    #[TestWith(['expiration_status=none', ['none']])]
    #[TestWith(['expiration_from=2026-11-17&expiration_to=2026-12-16', ['tomorrow', 'near']])]
    #[TestWith(['expiration_to=2026-11-16', ['expired', 'today']])]
    #[TestWith(['expiration_from=2026-12-17', ['valid', 'legacy']])]
    #[TestWith(['expiring_only=1&expiration_days=1', ['today', 'tomorrow']])]
    #[TestWith(['expiration_type=best_before&expiration_status=near&expiring_only=1&expiration_days=14', ['tomorrow']])]
    public function test_filters_are_applied_in_database(string $query, array $keys): void
    {
        $lots = $this->lots();
        $expected = collect($keys)->map(fn ($key) => $lots[$key]->id)->all();
        $this->get('/admin/inventory-lots?sort=expiration_asc&'.$query)->assertInertia(fn (Assert $page) => $page
            ->where('lots.total', count($expected))->where('lots.data', fn ($data) => collect($data)->pluck('id')->all() === $expected));
    }

    #[TestWith(['0'])]
    #[TestWith(['-1'])]
    #[TestWith(['1.5'])]
    #[TestWith(['3651'])]
    #[TestWith(['99999999999999999999999'])]
    #[TestWith(['abc'])]
    public function test_rejects_invalid_day_count(string $days): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/admin/inventory-lots?expiration_days='.$days)
            ->assertSessionHasErrors('expiration_days');
    }

    public function test_within_days_filter_requires_a_day_count(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/admin/inventory-lots?expiring_only=1&expiration_days=')
            ->assertSessionHasErrors(['expiration_days' => '期限までの日数を入力してください。']);
    }

    public function test_rejects_invalid_dates_range_and_options(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->get('/admin/inventory-lots?expiration_from=2026-02-30')->assertSessionHasErrors('expiration_from');
        $this->get('/admin/inventory-lots?expiration_from=2026-12-01&expiration_to=2026-11-01')->assertSessionHasErrors('expiration_to');
        $this->get('/admin/inventory-lots?expiration_type=invalid&expiration_status=invalid&sort=invalid')
            ->assertSessionHasErrors(['expiration_type', 'expiration_status', 'sort']);
    }

    public function test_expiration_sort_keeps_zero_stock_after_available_and_null_dates_last(): void
    {
        $lots = $this->lots();
        $lots['expired']->update(['current_quantity' => 0]);
        $expected = collect(['today', 'tomorrow', 'near', 'valid', 'legacy', 'none', 'expired'])->map(fn ($key) => $lots[$key]->id)->all();
        $this->get('/admin/inventory-lots?sort=expiration_asc')->assertInertia(fn (Assert $page) => $page
            ->where('lots.total', 7)->where('lots.data', fn ($data) => collect($data)->pluck('id')->all() === $expected));
        $this->get('/admin/inventory-lots?availability=depleted&expiration_status=expired')
            ->assertInertia(fn (Assert $page) => $page->where('lots.total', 1)->where('lots.data.0.id', $lots['expired']->id));
    }

    public function test_ties_use_receipt_date_then_sku_then_lot_number(): void
    {
        $lots = $this->lots();
        foreach (['expired', 'today', 'tomorrow', 'near'] as $key) {
            $lots[$key]->update(['expires_on' => '2026-11-20', 'received_on' => '2026-11-01']);
        }
        $lots['expired']->update(['received_on' => '2026-10-31']);
        $lots['tomorrow']->update(['lot_number' => 'AAA']);
        $lots['near']->update(['lot_number' => 'ZZZ']);
        $other = Product::factory()->create(['sku' => 'AAA']);
        $lots['today']->update(['product_id' => $other->id]);
        $this->get('/admin/inventory-lots?sort=expiration_asc&expiration_status=near')->assertInertia(fn (Assert $page) => $page
            ->where('lots.data', fn ($data) => collect($data)->pluck('id')->all() === [
                $lots['expired']->id, $lots['today']->id, $lots['tomorrow']->id, $lots['near']->id,
            ]));
    }

    public function test_pagination_preserves_filters_and_reset_returns_all_lots_with_config_defaults(): void
    {
        $lots = $this->lots();
        InventoryLot::factory()->count(22)->create(['expiration_type' => 'use_by', 'expires_on' => '2026-11-20']);
        $query = 'expiration_type=use_by&expiration_status=near&expiration_days=14&expiring_only=1&sort=expiration_asc';
        $this->get('/admin/inventory-lots?'.$query)->assertInertia(fn (Assert $page) => $page
            ->where('lots.total', 22)->has('lots.data', 20)
            ->where('lots.next_page_url', function ($url) use ($query) {
                parse_str(parse_url($url, PHP_URL_QUERY), $actual);
                parse_str($query, $expected);

                return $actual === [...$expected, 'page' => '2'];
            }));
        $this->get('/admin/inventory-lots?'.$query.'&page=2')->assertInertia(fn (Assert $page) => $page->has('lots.data', 2));
        config(['inventory.expiration_warning_days' => 7]);
        $this->get('/admin/inventory-lots')->assertInertia(fn (Assert $page) => $page
            ->where('lots.total', 29)->where('filters.expiration_days', 7)->where('filters.sort', 'received_desc')
            ->where('expirationSettings.warning_days', 7));
    }

    public function test_loading_more_lots_does_not_add_queries(): void
    {
        $this->lots();
        $this->get('/admin/inventory-lots')->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/admin/inventory-lots')->assertOk();
        $before = count(DB::getQueryLog());
        InventoryLot::factory()->count(13)->create();
        DB::flushQueryLog();
        $this->get('/admin/inventory-lots')->assertOk();
        $after = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($before, $after);
        $this->assertLessThanOrEqual(16, $after);
    }
}
