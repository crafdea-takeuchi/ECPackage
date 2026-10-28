<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(UnitSeeder::class);

        User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => 'password', 'email_verified_at' => now()],
        );

        $admin = User::firstOrNew(['email' => 'admin@crafdea.co.jp']);

        if (! $admin->exists) {
            $password = Str::password(24);
            $admin->forceFill([
                'name' => 'Admin',
                'password' => 'testtest1234',
                'email_verified_at' => now(),
            ]);
        }

        $admin->forceFill(['role' => 'admin'])->save();

        if ($admin->wasRecentlyCreated) {
            $this->command?->warn('Admin login: '.$admin->email);
            $this->command?->warn('Initial password: '.$password);
        }

        $this->call(ProductCatalogSeeder::class);

        // Demo data is opt-in: php artisan db:seed --class=DemoCatalogSeeder
    }
}
