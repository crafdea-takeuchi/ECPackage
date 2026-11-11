<?php

namespace App\Services\Csv;

use App\Contracts\CsvImportDefinition;
use InvalidArgumentException;

class CsvImportRegistry
{
    public function find(string $type): ?CsvImportDefinition
    {
        $class = config("csv-imports.definitions.{$type}");

        if (! is_string($class) || $class === '') {
            return null;
        }

        $definition = app($class);

        if (! $definition instanceof CsvImportDefinition) {
            throw new InvalidArgumentException("CSV取込定義 {$class} は CsvImportDefinition を実装していません。");
        }

        return $definition;
    }

    public function has(string $type): bool
    {
        return $this->find($type) !== null;
    }
}
