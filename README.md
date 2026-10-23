# ECPackage

## 前提条件

PHP・Composer・Node.js はコンテナの中で動くので、PC に入れる必要はありません。

### 必須

1. **WSL2 と Ubuntu**
   - 以下の手順は全部 WSL の Ubuntu のターミナルで実行します。PowerShell やコマンドプロンプトではありません。
2. **Docker**
   - WSL2 から使える Docker が必要です。
   - Docker Desktop を使う場合は、Settings → Resources → WSL Integration で Ubuntu を有効にしてください。
   - `docker ps` と `docker compose version` がエラーなく通れば OK です。
3. **Git**
   - Ubuntu 側に git が入っていること。
4. **リポジトリへのアクセス権**
   - `crafdea-takeuchi/ECPackage` を閲覧できる GitHub アカウントが必要です。
   - Private リポジトリの場合、HTTPS で Clone するときに Personal Access Token などの認証が要ります。
5. **使用ポートが空いていること**
   - 80、3306、5173、6379、1025、8025 を使います。
   - 被る場合は `.env` のポート設定を変えてください。`docker ps` で使用中のポートを確認できます。

### 推奨・注意

- **Clone は WSL のファイルシステム（例：`~/code`）に置く**
  - `/mnt/c/...`（Windows 側のフォルダ）に置くと、動作がかなり遅くなります。
- **WSL のユーザー ID が 1000 であること**
  - `id -u` で確認してください。1000 以外なら、`.env` の `WWWUSER` / `WWWGROUP` をその値に変えます。
- **初回はネットワークとディスク容量が要る**
  - イメージのビルドと取得で、数 GB のダウンロードと数分の待ち時間がかかります。

## 構築手順

```bash
# 1. Clone
cd ~/code
git clone https://github.com/crafdea-takeuchi/ECPackage.git ECPackage
cd ECPackage

# 2. .env 作成（DBパスワードを設定）
cp .env.example .env
sed -i 's/^DB_PASSWORD=$/DB_PASSWORD=password/' .env
#  ※他プロジェクトとポートが被る場合は .env の以下を変更
#    APP_PORT=80 / FORWARD_DB_PORT=3306 / VITE_PORT=5173 など
#    （例：他プロジェクトと同時に起動するなら FORWARD_DB_PORT=3307、VITE_PORT=5174）

# 3. Composer依存をインストール（vendor/bin/sail を用意するため Docker 経由）
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html \
  laravelsail/php84-composer:latest composer install --ignore-platform-reqs

# 4. コンテナ起動（初回はイメージのビルドで数分かかります）
./vendor/bin/sail up -d

# 5. アプリキー生成・DB構築・初期データ投入
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed

# 6. フロントエンド
./vendor/bin/sail npm install
./vendor/bin/sail npm run build      # 開発中は ./vendor/bin/sail npm run dev
```

## 動作確認

| 用途                      | URL                   |
| ------------------------- | --------------------- |
| アプリ                    | http://localhost      |
| Mailpit（送信メール確認） | http://localhost:8025 |

### ログインユーザー（Seeder で作成）

| 種別   | メールアドレス        | パスワード     |
| ------ | --------------------- | -------------- |
| 管理者 | `admin@crafdea.co.jp` | `testtest1234` |
| 一般   | `test@example.com`    | `password`     |

> [!NOTE]
> `db:seed` のときに画面に出る「Initial password」は、実際のパスワードと違います。上の `testtest1234` を使ってください。

## よく使うコマンド

```bash
./vendor/bin/sail up -d                          # 起動
./vendor/bin/sail stop                           # 停止
./vendor/bin/sail artisan migrate:fresh --seed   # DBを作り直す（データは消えます）
```

## ドキュメント

- [基本設計書](docs/基本設計書.md)
- [DB設計書](docs/DB設計書.md)

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
