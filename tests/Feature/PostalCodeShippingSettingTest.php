<?php

namespace Tests\Feature;

use App\Models\PostalCodeShippingGroup;
use App\Models\PostalCodeShippingPostalCode;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PostalCodeShippingSettingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function input(array $overrides = []): array
    {
        return array_replace([
            'name' => '特別配送地域A',
            'shipping_fee' => 2500,
            'free_shipping_threshold' => 30000,
            'postal_codes' => ['100-0101', '１０００１０２'],
        ], $overrides);
    }

    public function test_admin_can_create_update_and_delete_a_postal_code_shipping_group(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('admin.settings.shipping.postal-code-groups.store'), $this->input())
            ->assertRedirect(route('admin.settings.shipping.edit'))->assertSessionHasNoErrors()
            ->assertSessionHas('status', '郵便番号別送料グループを追加しました。');

        $group = PostalCodeShippingGroup::sole();
        $this->assertSame('2500.0000', $group->shipping_fee);
        $this->assertSame('30000.0000', $group->free_shipping_threshold);
        $this->assertSame(['1000101', '1000102'], $group->postalCodes()->pluck('postal_code')->all());

        $this->get(route('admin.settings.shipping.edit'))->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Settings/Shipping')->has('postalCodeGroups', 1)
            ->where('postalCodeGroups.0.name', '特別配送地域A')
            ->where('postalCodeGroups.0.postal_codes', ['1000101', '1000102'])
            ->where('postalCodeGroupStoreUrl', route('admin.settings.shipping.postal-code-groups.store', [], false)));

        $this->put(route('admin.settings.shipping.postal-code-groups.update', $group), $this->input([
            'name' => '東京都特別配送地域', 'shipping_fee' => 2800, 'free_shipping_threshold' => '',
            'postal_codes' => ['100-0102', '100-0103'],
        ]))->assertSessionHasNoErrors()->assertSessionHas('status', '郵便番号別送料グループを更新しました。');

        $group->refresh();
        $this->assertSame('東京都特別配送地域', $group->name);
        $this->assertSame('2800.0000', $group->shipping_fee);
        $this->assertNull($group->free_shipping_threshold);
        $this->assertSame(['1000102', '1000103'], $group->postalCodes()->pluck('postal_code')->all());

        $this->delete(route('admin.settings.shipping.postal-code-groups.destroy', $group))
            ->assertSessionHas('status', '郵便番号別送料グループを削除しました。');
        $this->assertDatabaseCount('postal_code_shipping_groups', 0);
        $this->assertDatabaseCount('postal_code_shipping_postal_codes', 0);
    }

    public function test_duplicate_and_invalid_postal_codes_are_rejected_without_changing_saved_groups(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.settings.shipping.postal-code-groups.store'), $this->input())
            ->assertSessionHasNoErrors();

        $this->post(route('admin.settings.shipping.postal-code-groups.store'), $this->input([
            'name' => '別グループ', 'postal_codes' => ['100-0101'],
        ]))->assertSessionHasErrors('postal_codes');

        $this->post(route('admin.settings.shipping.postal-code-groups.store'), $this->input([
            'name' => '形式不正', 'postal_codes' => ['100-01A1'],
        ]))->assertSessionHasErrors('postal_codes.0');

        $this->post(route('admin.settings.shipping.postal-code-groups.store'), $this->input([
            'name' => '入力内重複', 'postal_codes' => ['100-0201', '1000201'],
        ]))->assertSessionHasErrors('postal_codes.1');

        $group = PostalCodeShippingGroup::sole();
        $this->put(route('admin.settings.shipping.postal-code-groups.update', $group), $this->input([
            'name' => '変更失敗', 'postal_codes' => ['12345'],
        ]))->assertSessionHasErrors('postal_codes.0');
        $this->assertSame('特別配送地域A', $group->refresh()->name);
        $this->assertSame(['1000101', '1000102'], $group->postalCodes()->pluck('postal_code')->all());

        $this->assertDatabaseCount('postal_code_shipping_groups', 1);
        $this->assertDatabaseCount('postal_code_shipping_postal_codes', 2);
    }

    public function test_postal_code_shipping_group_routes_use_existing_admin_permissions(): void
    {
        $this->post(route('admin.settings.shipping.postal-code-groups.store'), $this->input())->assertRedirect('/admin/login');

        $this->actingAs(User::factory()->create())
            ->post(route('admin.settings.shipping.postal-code-groups.store'), $this->input())->assertForbidden();

        $this->actingAs(User::factory()->admin()->unverified()->create())
            ->post(route('admin.settings.shipping.postal-code-groups.store'), $this->input())->assertRedirect('/email/verify');

        $this->assertDatabaseCount('postal_code_shipping_groups', 0);
        $this->assertDatabaseCount('postal_code_shipping_postal_codes', 0);
    }

    public function test_postal_code_normalizer_accepts_hyphenated_and_full_width_values(): void
    {
        $this->assertSame('1000101', PostalCodeShippingPostalCode::normalize('100-0101'));
        $this->assertSame('1000101', PostalCodeShippingPostalCode::normalize('１００－０１０１'));
        $this->assertNull(PostalCodeShippingPostalCode::normalize('100-01A1'));
    }
}
