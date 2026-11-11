<?php

namespace App\Enums;

enum CsvImportStatus: string
{
    case Uploaded = 'uploaded';
    case Validating = 'validating';
    case Ready = 'ready';
    case Invalid = 'invalid';
    case Importing = 'importing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'アップロード済み',
            self::Validating => '検証中',
            self::Ready => '取込待ち',
            self::Invalid => '入力エラー',
            self::Importing => '取込中',
            self::Completed => '完了',
            self::Failed => 'システムエラー',
        };
    }

    public function isProcessing(): bool
    {
        return in_array($this, [self::Uploaded, self::Validating, self::Importing], true);
    }
}
