<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsvImportError extends Model
{
    use HasFactory;

    protected $fillable = ['row_number', 'column', 'message', 'raw_data'];

    protected function casts(): array
    {
        return ['row_number' => 'integer', 'raw_data' => 'array'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(CsvImport::class, 'csv_import_id');
    }
}
