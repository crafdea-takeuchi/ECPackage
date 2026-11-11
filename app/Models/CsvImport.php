<?php

namespace App\Models;

use App\Enums\CsvImportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CsvImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'type', 'original_filename', 'disk', 'path', 'file_hash', 'encoding',
        'status', 'total_rows', 'valid_rows', 'error_rows', 'imported_rows',
        'options', 'system_error', 'started_at', 'finished_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => CsvImportStatus::class,
            'options' => 'array',
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'error_rows' => 'integer',
            'imported_rows' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function errors(): HasMany
    {
        return $this->hasMany(CsvImportError::class)->orderBy('row_number')->orderBy('id');
    }
}
