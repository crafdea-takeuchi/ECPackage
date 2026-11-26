<?php

namespace Tests\Feature;

use App\Models\OrderSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class GoogleAnalyticsSettingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function saveMeasurementId(?string $id): void
    {
        $settings = OrderSetting::current();
        $settings->google_analytics_id = $id;
        $settings->save();
    }

    public function test_settings_hub_and_form_show_unset_measurement_id(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('admin.settings.index'))->assertInertia(fn (Assert $page) => $page
            ->where('cards.4.title', 'Googleアナリティクス設定')
            ->where('cards.4.status', '現在の測定ID：未設定（計測しません）')
            ->where('cards.4.href', route('admin.settings.google-analytics.edit', [], false)));

        $this->get(route('admin.settings.google-analytics.edit'))->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Settings/GoogleAnalytics')
            ->where('googleAnalyticsId', null)
            ->where('saveUrl', route('admin.settings.google-analytics.update', [], false))
            ->where('settingsUrl', route('admin.settings.index', [], false)));
    }

    public function test_admin_saves_normalized_measurement_id_without_touching_other_settings(): void
    {
        $settings = OrderSetting::current();
        $settings->system_name = 'テストストア';
        $settings->save();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.settings.google-analytics.update'), ['google_analytics_id' => '  g-abc123xyz9 '])
            ->assertRedirect(route('admin.settings.google-analytics.edit'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Googleアナリティクスの設定を保存しました。');

        $settings->refresh();
        $this->assertSame('G-ABC123XYZ9', $settings->google_analytics_id);
        $this->assertSame('テストストア', $settings->system_name);

        $this->get(route('admin.settings.index'))->assertInertia(fn (Assert $page) => $page
            ->where('cards.4.status', '現在の測定ID：G-ABC123XYZ9'));
    }

    public function test_clearing_measurement_id_stops_tracking(): void
    {
        $this->saveMeasurementId('G-ABC123XYZ9');

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.settings.google-analytics.update'), ['google_analytics_id' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull(OrderSetting::current()->google_analytics_id);
        $this->post('/logout');
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page->where('googleAnalyticsId', null));
    }

    #[TestWith(['UA-12345678-1'])]
    #[TestWith(['G-'])]
    #[TestWith(['G-ABC<script>'])]
    #[TestWith(['G-ABCDEFGHIJKLMNOPQRSTUVWXYZ'])]
    #[TestWith([['G-ABC123XYZ9']])]
    public function test_invalid_measurement_id_is_rejected(mixed $id): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.settings.google-analytics.update'), ['google_analytics_id' => $id])
            ->assertSessionHasErrors('google_analytics_id');

        $this->assertNull(OrderSetting::current()->google_analytics_id);
    }

    public function test_measurement_id_is_shared_only_with_customer_facing_pages(): void
    {
        $this->saveMeasurementId('G-ABC123XYZ9');

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('googleAnalyticsId', 'G-ABC123XYZ9'));
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page->where('googleAnalyticsId', 'G-ABC123XYZ9'));
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('googleAnalyticsId', 'G-ABC123XYZ9'));
        $this->get('/admin/login')->assertInertia(fn (Assert $page) => $page->where('googleAnalyticsId', null));

        $this->actingAs(User::factory()->create());
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page->where('googleAnalyticsId', 'G-ABC123XYZ9'));

        $this->actingAs(User::factory()->admin()->create());
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page->where('googleAnalyticsId', null));
        $this->get(route('admin.settings.index'))->assertInertia(fn (Assert $page) => $page->where('googleAnalyticsId', null));
    }

    public function test_only_verified_admin_can_open_and_update_measurement_id(): void
    {
        $this->get(route('admin.settings.google-analytics.edit'))->assertRedirect('/admin/login');
        $this->put(route('admin.settings.google-analytics.update'), ['google_analytics_id' => 'G-ABC123XYZ9'])->assertRedirect('/admin/login');

        $this->actingAs(User::factory()->create());
        $this->get(route('admin.settings.google-analytics.edit'))->assertForbidden();
        $this->put(route('admin.settings.google-analytics.update'), ['google_analytics_id' => 'G-ABC123XYZ9'])->assertForbidden();

        $this->actingAs(User::factory()->admin()->unverified()->create());
        $this->put(route('admin.settings.google-analytics.update'), ['google_analytics_id' => 'G-ABC123XYZ9'])->assertRedirect('/email/verify');

        $this->assertNull(OrderSetting::current()->google_analytics_id);
    }
}
