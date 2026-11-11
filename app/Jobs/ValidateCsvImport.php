<?php

namespace App\Jobs;

use App\Enums\CsvImportStatus;
use App\Models\CsvImport;
use App\Services\Csv\CsvImportManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ValidateCsvImport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $csvImportId) {}

    public function uniqueId(): string
    {
        return (string) $this->csvImportId;
    }

    public function handle(CsvImportManager $manager): void
    {
        $manager->validate(CsvImport::findOrFail($this->csvImportId));
    }

    public function failed(?Throwable $exception): void
    {
        CsvImport::whereKey($this->csvImportId)->update([
            'status' => CsvImportStatus::Failed,
            'system_error' => $exception?->getMessage() ?? 'CSV検証ジョブが失敗しました。',
            'finished_at' => now(),
        ]);
    }
}
