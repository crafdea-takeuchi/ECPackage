<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin';

    protected $description = 'Create an initial administrator using interactive, hidden password input';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('対話モードで実行してください。');

            return self::FAILURE;
        }
        $data = ['name' => $this->ask('管理者のお名前'), 'email' => mb_strtolower(trim((string) $this->ask('メールアドレス'))),
            'password' => $this->secret('パスワード（英字と数字を含む12文字以上）'),
            'password_confirmation' => $this->secret('パスワード（確認）')];
        $validator = Validator::make($data, ['name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'max:72', 'confirmed', Password::min(12)->letters()->numbers()]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        $user = new User(collect($data)->only('name', 'email', 'password')->all());
        $user->role = 'admin';
        $user->email_verified_at = now();
        $user->save();
        $this->info('管理者を作成しました。/admin/login からログインできます。');

        return self::SUCCESS;
    }
}
