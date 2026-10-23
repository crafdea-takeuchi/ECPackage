<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function account(): array
    {
        return ['name' => 'テスト利用者', 'email' => 'new@example.test', 'password' => 'Example-pass123', 'password_confirmation' => 'Example-pass123'];
    }

    #[TestWith(['/login', 'Auth/Login'])]
    #[TestWith(['/admin/login', 'Auth/Login'])]
    #[TestWith(['/register', 'Auth/Register'])]
    #[TestWith(['/forgot-password', 'Auth/ForgotPassword'])]
    public function test_guest_pages_render(string $url, string $component): void
    {
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
    }

    public function test_customer_registration_hashes_password_and_sends_verification(): void
    {
        Notification::fake();
        $this->post('/register', $this->account())->assertRedirect('/email/verify');
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('customer', $user->role);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check('Example-pass123', $user->password));
        $this->assertAuthenticatedAs($user);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_public_registration_cannot_assign_admin_role(): void
    {
        $this->post('/register', [...$this->account(), 'role' => 'admin'])->assertSessionHasErrors('role');
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_registration_rejects_duplicate_email_and_invalid_password(): void
    {
        User::factory()->create(['email' => 'new@example.test']);
        $this->post('/register', [...$this->account(), 'password' => 'short'])->assertSessionHasErrors(['email', 'password']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_missing_registration_fields_have_japanese_errors(): void
    {
        $this->post('/register', [])->assertSessionHasErrors(['name' => 'お名前を入力してください。', 'email' => 'メールアドレスを入力してください。', 'password' => 'パスワードを入力してください。']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_customer_logs_in_to_home_and_can_log_out(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_invalid_password_does_not_authenticate(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'incorrect'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_customer_cannot_use_admin_login(): void
    {
        $user = User::factory()->create();
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[TestWith(['/login'])]
    #[TestWith(['/admin/login'])]
    public function test_admin_lands_on_dashboard_and_can_visit_home(string $url): void
    {
        $user = User::factory()->admin()->create();
        $this->post($url, ['email' => $user->email, 'password' => 'password'])->assertRedirect('/admin');
        $this->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard')->missing('users.data.0.password'));
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Home')->where('auth.user.role', 'admin')->missing('auth.user.password'));
    }

    public function test_guests_and_customers_cannot_access_admin_routes(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->post('/admin/users', $this->account())->assertRedirect('/admin/login');
        $customer = User::factory()->create();
        $this->actingAs($customer)->get('/admin')->assertForbidden();
        $this->get('/admin/users/create')->assertForbidden();
        $this->post('/admin/users', [...$this->account(), 'role' => 'admin'])->assertForbidden();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_gate_allows_only_administrators(): void
    {
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('access-admin'));
        $this->assertTrue(Gate::forUser(User::factory()->admin()->create())->allows('access-admin'));
    }

    public function test_unverified_admin_must_verify_before_admin_operations(): void
    {
        $this->actingAs(User::factory()->admin()->unverified()->create())->get('/admin')->assertRedirect('/email/verify');
        $this->post('/admin/users', [...$this->account(), 'role' => 'admin'])->assertRedirect('/email/verify');
        $this->assertDatabaseCount('users', 1);
    }

    #[TestWith(['customer'])]
    #[TestWith(['admin'])]
    public function test_admin_can_create_accounts_without_changing_own_session(string $role): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/admin/users', [...$this->account(), 'role' => $role])->assertRedirect('/admin');
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame($role, $user->role);
        $this->assertNull($user->email_verified_at);
        $this->assertAuthenticatedAs($admin);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_invalid_admin_role_does_not_create_account(): void
    {
        $this->actingAs(User::factory()->admin()->create())->post('/admin/users', [...$this->account(), 'role' => 'superuser'])->assertSessionHasErrors('role');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_login_attempts_are_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'absent@example.test', 'password' => 'incorrect'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => 'absent@example.test', 'password' => 'incorrect'])->assertStatus(429);
    }

    public function test_email_can_be_verified_using_signed_link(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($user)->get($url)->assertRedirect('/');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_invalid_or_other_users_verification_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $other->id, 'hash' => sha1($other->email)]);
        $this->actingAs($user)->get($url)->assertForbidden();
        $this->get('/email/verify/'.$user->id.'/'.sha1($user->email))->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertNull($other->fresh()->email_verified_at);
    }

    public function test_unverified_user_can_resend_email(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->post('/email/verification-notification')->assertSessionHas('status');
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_recovery_does_not_disclose_whether_email_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $status = '登録されたメールアドレスの場合、再設定用のリンクを送信しました。';
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status', $status);
        $this->post('/forgot-password', ['email' => 'absent@example.test'])->assertSessionHas('status', $status);
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_password_reset_changes_password_and_invalidates_sessions(): void
    {
        $user = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $token = Password::createToken($user);
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'New-password123', 'password_confirmation' => 'New-password123'];
        $this->post('/reset-password', $data)->assertRedirect('/login');
        $this->assertTrue(Hash::check('New-password123', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->post('/reset-password', $data)->assertSessionHasErrors('email');
    }

    public function test_invalid_reset_token_does_not_change_password(): void
    {
        $user = User::factory()->create();
        $this->post('/reset-password', ['email' => $user->email, 'token' => 'invalid', 'password' => 'New-password123', 'password_confirmation' => 'New-password123'])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_admin_bootstrap_command_creates_admin_with_hidden_password_prompt(): void
    {
        $this->artisan('app:create-admin')
            ->expectsQuestion('管理者のお名前', '管理者')
            ->expectsQuestion('メールアドレス', 'owner@example.test')
            ->expectsQuestion('パスワード（英字と数字を含む12文字以上）', 'Owner-password123')
            ->expectsQuestion('パスワード（確認）', 'Owner-password123')
            ->assertSuccessful();
        $user = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertSame('admin', $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('Owner-password123',$user->password));
    }
}
