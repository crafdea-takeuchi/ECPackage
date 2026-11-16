<?php

namespace Tests\Integration;

use App\Actions\Inventory\DisposeExpiredInventory;
use App\Actions\Inventory\RecordStockOperation;
use App\Models\InventoryLot;
use App\Models\StockOperation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ExpiredInventoryDisposalConcurrencyTest extends TestCase
{
    #[TestWith(['outbound', 0, [-3, -7]])]
    #[TestWith(['dispose', 2, [-10]])]
    public function test_row_lock_serializes_disposal_with_other_stock_operations(string $firstOperation, int $exitCode, array $deltas): void
    {
        $this->assertSame('mysql', DB::getDriverName(), '専用のMySQLテスト設定で実行してください。');
        $this->assertMatchesRegularExpression('/^testing_disposal_[a-f0-9]+$/', DB::getDatabaseName(), '業務DBでは実行できません。');
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();

        $actor = User::factory()->admin()->create();
        $lot = InventoryLot::factory()->create([
            'expires_on' => CarbonImmutable::today('Asia/Tokyo')->subDays(3)->toDateString(),
            'expiration_type' => 'use_by', 'current_quantity' => 10, 'status' => 'active',
        ]);
        $input = [
            'lots' => [['inventory_lot_id' => $lot->id, 'product_id' => $lot->product_id]],
            'occurred_on' => CarbonImmutable::today('Asia/Tokyo')->toDateString(),
            'reason' => DisposeExpiredInventory::REASON,
        ];
        $workerCode = <<<'PHP'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo "STARTED\n";
flush();
try {
    app(App\Actions\Inventory\DisposeExpiredInventory::class)->handle(
        json_decode($argv[1], true), App\Models\User::findOrFail((int) $argv[2])
    );
    echo "DISPOSED\n";
} catch (Illuminate\Validation\ValidationException $exception) {
    echo "REJECTED\n";
    exit(2);
}
PHP;
        $config = config('database.connections.mysql');
        $worker = new Process([PHP_BINARY, '-r', $workerCode, json_encode($input), (string) $actor->id], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::getDatabaseName(),
            'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
            'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
            'DB_URL' => '',
        ]);
        $worker->setTimeout(15);
        DB::beginTransaction();
        try {
            InventoryLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
            $worker->start();
            $this->assertTrue($worker->waitUntil(fn ($type, $output): bool => str_contains($output, 'STARTED')), $worker->getErrorOutput());
            $waiting = false;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                foreach (DB::select('SHOW PROCESSLIST') as $connection) {
                    $sql = strtolower($connection->Info ?? '');
                    if (str_contains($sql, 'inventory_lots') && str_contains($sql, 'for update')) {
                        $waiting = true;
                        break 2;
                    }
                }
                usleep(20000);
            }
            $this->assertTrue($waiting, '別プロセスがロットの行ロックを待つ必要があります。');
            if ($firstOperation === 'outbound') {
                app(RecordStockOperation::class)->handle([
                    'operation_type' => 'outbound', 'occurred_on' => $input['occurred_on'],
                    'lines' => [['inventory_lot_id' => $lot->id, 'quantity' => '3']],
                ], $actor);
            } else {
                app(DisposeExpiredInventory::class)->handle($input, $actor);
            }
            DB::commit();
            $worker->wait();
            $this->assertSame($exitCode, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertSame('0.0000', $lot->fresh()->current_quantity);
            $this->assertSame('depleted', $lot->fresh()->status);
            $this->assertSame($deltas, DB::table('stock_operation_lines')->orderBy('id')->pluck('quantity_delta')->map(fn ($value): int => (int) $value)->all());
            $this->assertSame(count($deltas), StockOperation::count());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }
}
