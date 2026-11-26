<?php

namespace Tests\Feature;

use App\Models\OrderSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Mail\Markdown;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SystemNameSettingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_settings_hub_and_form_show_default_system_name(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('admin.settings.index'))->assertInertia(fn (Assert $page) => $page
            ->has('cards', 5)
            ->where('cards.3.title', 'システム名設定')
            ->where('cards.3.status', '現在のシステム名：EC02')
            ->where('cards.3.href', route('admin.settings.system-name.edit', [], false)));

        $this->get(route('admin.settings.system-name.edit'))->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Settings/SystemName')
            ->where('systemName', 'EC02')
            ->where('saveUrl', route('admin.settings.system-name.update', [], false))
            ->where('settingsUrl', route('admin.settings.index', [], false)));
    }

    public function test_saved_name_is_shared_with_pages_and_laravel_notification_mail(): void
    {
        $settings = OrderSetting::current();
        $settings->cash_on_delivery_fee = 330;
        $settings->save();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.settings.system-name.update'), ['system_name' => '  コクレアストア  '])
            ->assertRedirect(route('admin.settings.system-name.edit'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'システム名を保存しました。');

        $settings->refresh();
        $this->assertSame('コクレアストア', $settings->system_name);
        $this->assertSame('330.0000', $settings->cash_on_delivery_fee);
        $this->assertDatabaseCount('order_settings', 1);

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('systemName', 'コクレアストア'));
        $this->assertSame('コクレアストア', config('app.name'));
        $this->assertSame('コクレアストア', config('mail.from.name'));

        $html = (string) app(Markdown::class)->render('notifications::email', [
            'level' => 'primary',
            'greeting' => 'テスト',
            'introLines' => ['本文'],
            'outroLines' => [],
            'actionText' => null,
            'actionUrl' => null,
            'displayableActionUrl' => null,
            'salutation' => null,
        ]);
        $this->assertStringContainsString('コクレアストア', $html);
        $this->assertStringNotContainsString('ecpackage', $html);
    }

    #[TestWith([''])]
    #[TestWith(['   '])]
    #[TestWith(["1行目\n2行目"])]
    #[TestWith([['配列']])]
    public function test_invalid_system_name_is_rejected(mixed $name): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.settings.system-name.update'), ['system_name' => $name])
            ->assertSessionHasErrors('system_name');

        $this->assertSame('EC02', OrderSetting::current()->system_name);
    }

    public function test_too_long_system_name_is_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('admin.settings.system-name.update'), ['system_name' => str_repeat('名', 101)])
            ->assertSessionHasErrors('system_name');
    }

    public function test_only_verified_admin_can_open_and_update_system_name(): void
    {
        $this->get(route('admin.settings.system-name.edit'))->assertRedirect('/admin/login');
        $this->put(route('admin.settings.system-name.update'), ['system_name' => '変更'])->assertRedirect('/admin/login');

        $this->actingAs(User::factory()->create());
        $this->get(route('admin.settings.system-name.edit'))->assertForbidden();
        $this->put(route('admin.settings.system-name.update'), ['system_name' => '変更'])->assertForbidden();

        $this->actingAs(User::factory()->admin()->unverified()->create());
        $this->get(route('admin.settings.system-name.edit'))->assertRedirect('/email/verify');
        $this->put(route('admin.settings.system-name.update'), ['system_name' => '変更'])->assertRedirect('/email/verify');

        $this->assertSame('EC02', OrderSetting::current()->system_name);
    }
}
