<?php

namespace App\Contracts;

use App\Models\CsvImport;
use App\Models\User;

interface CsvImportDefinition
{
    public function type(): string;

    /** @return array<int, string> */
    public function requiredHeaders(): array;

    /**
     * @param  array<string, string|null>  $row
     * @return array<int, array{column: string|null, message: string}>
     */
    public function validateRow(array $row, int $rowNumber): array;

    /**
     * @param  array<int, array<string, string|null>>  $rows
     */
    public function importRows(array $rows, User $user, CsvImport $import): int;

    /** @return array<int, array<string, string|null>> */
    public function templateRows(): array;
}
