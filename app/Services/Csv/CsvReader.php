<?php

namespace App\Services\Csv;

use App\Exceptions\CsvImportFormatException;
use Illuminate\Support\Facades\Storage;

class CsvReader
{
    /** @return array{encoding: string, headers: list<string>, total_rows: int, rows: list<array{row_number: int, data: array<string, string>, errors: list<array{column: string|null, message: string}>}>} */
    public function read(string $disk, string $path): array
    {
        $contents = Storage::disk($disk)->get($path);
        $encoding = $this->detectEncoding($contents);
        $utf8 = $this->toUtf8($contents, $encoding);
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new CsvImportFormatException('CSVファイルを読み込めませんでした。');
        }

        fwrite($stream, $utf8);
        rewind($stream);
        $rawHeaders = fgetcsv($stream, null, ',', '"', '');

        if ($rawHeaders === false) {
            fclose($stream);
            throw new CsvImportFormatException('CSVファイルが空です。');
        }

        $headers = array_map(static fn (mixed $header): string => trim((string) $header), $rawHeaders);

        if ($headers === [] || in_array('', $headers, true)) {
            fclose($stream);
            throw new CsvImportFormatException('ヘッダーに空の項目があります。');
        }

        if (count(array_unique($headers)) !== count($headers)) {
            fclose($stream);
            throw new CsvImportFormatException('ヘッダー名が重複しています。');
        }

        $rows = [];
        $rowNumber = 1;
        $maxRows = (int) config('csv-imports.max_rows', 10000);

        while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rowNumber++;

            if ($this->isEmptyRow($values)) {
                continue;
            }

            if (count($rows) >= $maxRows) {
                fclose($stream);
                throw new CsvImportFormatException("CSVは{$maxRows}行以内にしてください。");
            }

            $errors = [];
            if (count($values) !== count($headers)) {
                $errors[] = [
                    'column' => null,
                    'message' => sprintf('列数が一致しません（期待: %d列、実際: %d列）。', count($headers), count($values)),
                ];
            }

            $normalized = array_pad(array_slice($values, 0, count($headers)), count($headers), '');
            $data = [];
            foreach ($headers as $index => $header) {
                $data[$header] = trim((string) ($normalized[$index] ?? ''));
            }

            $rows[] = ['row_number' => $rowNumber, 'data' => $data, 'errors' => $errors];
        }

        fclose($stream);

        if ($rows === []) {
            throw new CsvImportFormatException('データ行がありません。');
        }

        return ['encoding' => $encoding, 'headers' => $headers, 'total_rows' => count($rows), 'rows' => $rows];
    }

    private function detectEncoding(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            return 'UTF-8 BOM';
        }

        if (mb_check_encoding($contents, 'UTF-8')) {
            return 'UTF-8';
        }

        if (mb_check_encoding($contents, 'SJIS-win')) {
            return 'CP932';
        }

        throw new CsvImportFormatException('文字コードを判定できません。UTF-8またはCP932で保存してください。');
    }

    private function toUtf8(string $contents, string $encoding): string
    {
        if ($encoding === 'UTF-8 BOM') {
            return substr($contents, 3);
        }

        if ($encoding === 'UTF-8') {
            return $contents;
        }

        $converted = mb_convert_encoding($contents, 'UTF-8', 'SJIS-win');

        if (! mb_check_encoding($converted, 'UTF-8')) {
            throw new CsvImportFormatException('CSVの文字コード変換に失敗しました。');
        }

        return $converted;
    }

    /** @param array<int, string|null> $values */
    private function isEmptyRow(array $values): bool
    {
        return collect($values)->every(static fn (mixed $value): bool => trim((string) $value) === '');
    }
}
