<?php

namespace App\Services\Csv;

use App\Enums\CsvImportStatus;
use App\Exceptions\CsvImportFormatException;
use App\Models\CsvImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Throwable;

class CsvImportManager
{
    public function __construct(
        private readonly CsvReader $reader,
        private readonly CsvImportRegistry $registry,
    ) {}

    public function validate(CsvImport $import): void
    {
        $import->update([
            'status' => CsvImportStatus::Validating,
            'system_error' => null,
            'started_at' => now(),
            'finished_at' => null,
        ]);
        $import->errors()->delete();

        try {
            $parsed = $this->reader->read($import->disk, $import->path);
            $errors = [];
            $definition = $this->registry->find($import->type);

            if (hash('sha256', Storage::disk($import->disk)->get($import->path)) !== $import->file_hash) {
                throw new CsvImportFormatException('アップロード後にCSVファイルが変更されています。');
            }

            if ($definition !== null) {
                foreach (array_diff($definition->requiredHeaders(), $parsed['headers']) as $header) {
                    $errors[] = ['row_number' => 1, 'column' => $header, 'message' => "必須ヘッダー「{$header}」がありません。", 'raw_data' => null];
                }
            }

            foreach ($parsed['rows'] as $row) {
                $rowErrors = $row['errors'];

                if ($rowErrors === [] && $definition !== null) {
                    $rowErrors = $definition->validateRow($row['data'], $row['row_number']);
                }

                foreach ($rowErrors as $error) {
                    $errors[] = [
                        'row_number' => $row['row_number'],
                        'column' => $error['column'] ?? null,
                        'message' => $error['message'],
                        'raw_data' => $row['data'],
                    ];
                }
            }

            foreach (array_chunk($errors, 500) as $chunk) {
                $import->errors()->createMany($chunk);
            }

            $errorRows = collect($errors)->pluck('row_number')->unique()->count();
            $import->update([
                'encoding' => $parsed['encoding'],
                'status' => $errors === [] ? CsvImportStatus::Ready : CsvImportStatus::Invalid,
                'total_rows' => $parsed['total_rows'],
                'valid_rows' => max(0, $parsed['total_rows'] - $errorRows),
                'error_rows' => $errorRows,
                'imported_rows' => 0,
                'finished_at' => now(),
            ]);
        } catch (CsvImportFormatException $exception) {
            $import->errors()->create([
                'row_number' => 1,
                'column' => null,
                'message' => $exception->getMessage(),
                'raw_data' => null,
            ]);
            $import->update([
                'status' => CsvImportStatus::Invalid,
                'valid_rows' => 0,
                'error_rows' => 1,
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $import->update([
                'status' => CsvImportStatus::Failed,
                'system_error' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }

    /** @return array{headers: list<string>, rows: list<array{row_number: int, data: array<string, string>}>} */
    public function preview(CsvImport $import): array
    {
        $parsed = $this->reader->read($import->disk, $import->path);
        $limit = (int) config('csv-imports.preview_rows', 20);

        return [
            'headers' => $parsed['headers'],
            'rows' => array_map(
                static fn (array $row): array => ['row_number' => $row['row_number'], 'data' => $row['data']],
                array_slice($parsed['rows'], 0, $limit),
            ),
        ];
    }

    public function execute(CsvImport $import): void
    {
        try {
            DB::transaction(function () use ($import): void {
                $lockedImport = CsvImport::query()->lockForUpdate()->findOrFail($import->id);

                if ($lockedImport->status !== CsvImportStatus::Ready) {
                    throw new LogicException('検証済みで取込待ちのCSVだけ実行できます。');
                }

                $definition = $this->registry->find($lockedImport->type);

                if ($definition === null) {
                    throw new LogicException('このCSV種別の実登録処理はまだ設定されていません。');
                }

                $lockedImport->update([
                    'status' => CsvImportStatus::Importing,
                    'started_at' => now(),
                    'finished_at' => null,
                    'system_error' => null,
                ]);

                $parsed = $this->reader->read($lockedImport->disk, $lockedImport->path);

                if (collect($parsed['rows'])->contains(static fn (array $row): bool => $row['errors'] !== [])) {
                    throw new LogicException('CSVの構造が検証時から変わっています。');
                }

                $rows = array_column($parsed['rows'], 'data');
                $imported = $definition->importRows($rows, $lockedImport->creator, $lockedImport);

                $lockedImport->update([
                    'status' => CsvImportStatus::Completed,
                    'imported_rows' => $imported,
                    'finished_at' => now(),
                ]);
            }, 3);
        } catch (CsvImportFormatException $exception) {
            $import->refresh()->update([
                'status' => CsvImportStatus::Failed,
                'system_error' => $exception->getMessage(),
                'imported_rows' => 0,
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }
}
