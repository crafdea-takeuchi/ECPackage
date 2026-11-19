<?php

namespace App\Actions\Products;

use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ProductSchedule
{
    public const FIELDS = [
        'publication_starts_at' => '公開開始日時',
        'publication_ends_at' => '公開終了日時',
        'sale_starts_at' => '販売開始日時',
        'sale_ends_at' => '販売終了日時',
    ];

    public const LABELS = [
        'private' => '非公開', 'before_publication' => '公開前', 'before_sale' => '販売前',
        'on_sale' => '販売中', 'sale_ended' => '販売終了', 'publication_ended' => '公開終了',
    ];

    public static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            return null;
        }
        foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value, config('products.schedule_timezone'));
                if ($date && $date->year >= 1000 && $date->format($format) === $value) {
                    return $date;
                }
            } catch (\Throwable) {
                // Try the other explicitly supported datetime-local/CSV formats.
            }
        }

        return null;
    }

    /** @return array<string, string> */
    public static function errors(array $input): array
    {
        $errors = [];
        $dates = [];
        foreach (self::FIELDS as $field => $label) {
            $value = $input[$field] ?? null;
            $dates[$field] = self::parse($value);
            if ($value !== null && $value !== '' && $dates[$field] === null) {
                $errors[$field] = $label.'は有効な日時（YYYY-MM-DD HH:mm:ss、Asia/Tokyo）で入力してください。';
            }
        }
        if ($errors !== []) {
            return $errors;
        }
        [$ps, $pe, $ss, $se] = array_values($dates);
        foreach ([['publication_ends_at', $ps, $pe, '公開'], ['sale_ends_at', $ss, $se, '販売']] as [$field, $start, $end, $label]) {
            if ($start && $end && $start->greaterThanOrEqualTo($end)) {
                $errors[$field] = $label.'終了日時は開始日時より後にしてください。';
            }
        }
        if (($ps && $ss && $ss->lessThan($ps)) || ($pe && $ss && $ss->greaterThanOrEqualTo($pe))) {
            $errors['sale_starts_at'] = '販売開始日時は公開期間内に設定してください。';
        }
        if (($pe && $se && $se->greaterThan($pe)) || ($ps && $se && $se->lessThanOrEqualTo($ps))) {
            $errors['sale_ends_at'] = '販売終了日時は公開期間内に設定してください。';
        }

        return $errors;
    }

    public static function storageValues(array $input): array
    {
        $values = [];
        foreach (self::FIELDS as $field => $label) {
            if (array_key_exists($field, $input)) {
                $values[$field] = self::parse($input[$field])?->utc()->format('Y-m-d H:i:s');
            }
        }

        return $values;
    }

    public static function formValues(?Product $product): array
    {
        $values = [];
        foreach (self::FIELDS as $field => $label) {
            $values[$field] = $product?->$field?->setTimezone(config('products.schedule_timezone'))->format('Y-m-d\TH:i:s') ?? '';
        }

        return $values;
    }

    public static function visibleQuery(Builder $query): Builder
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');

        return $query->where('products.registration_status', 'confirmed')->where('products.publication_status', 'public')
            ->whereNotNull('products.default_sale_price')
            ->where(fn ($q) => $q->whereNull('products.publication_starts_at')->orWhere('products.publication_starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('products.publication_ends_at')->orWhere('products.publication_ends_at', '>', $now));
    }

    public static function state(Product $product): string
    {
        $now = CarbonImmutable::now('UTC');
        if ($product->registration_status !== 'confirmed' || $product->publication_status !== 'public') {
            return 'private';
        }
        if ($product->publication_starts_at && $now->lessThan($product->publication_starts_at)) {
            return 'before_publication';
        }
        if ($product->publication_ends_at && $now->greaterThanOrEqualTo($product->publication_ends_at)) {
            return 'publication_ended';
        }
        if ($product->sale_starts_at && $now->lessThan($product->sale_starts_at)) {
            return 'before_sale';
        }
        if ($product->sale_ends_at && $now->greaterThanOrEqualTo($product->sale_ends_at)) {
            return 'sale_ended';
        }

        return 'on_sale';
    }

    public static function visible(Product $product, bool $requirePrice = true): bool
    {
        return (! $requirePrice || $product->default_sale_price !== null)
            && in_array(self::state($product), ['before_sale', 'on_sale', 'sale_ended'], true);
    }

    public static function reason(Product $product): ?string
    {
        return match (self::state($product)) {
            'private' => '現在非公開の商品です。',
            'before_publication' => '公開開始前です。',
            'publication_ended' => '公開は終了しました。',
            'before_sale' => '販売開始前です。'.$product->sale_starts_at->setTimezone(config('products.schedule_timezone'))->format('n月j日 H:i').'から販売開始',
            'sale_ended' => '販売は終了しました。',
            default => $product->default_sale_price === null ? '販売価格が設定されていません。' : null,
        };
    }

    public static function ensurePurchasable(Product $product, string $key = 'cart'): void
    {
        if ($reason = self::reason($product)) {
            throw ValidationException::withMessages([$key => $product->name.'：'.$reason]);
        }
    }
}
