<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Products\StoreProductImageFile;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductImageFileRequest;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * 販売商品の画像を、操作した時点で保存する（追加・差し替え・説明の変更・並べ替え・削除）。
 * 画像の操作では販売商品の lock_version を上げないので、編集中の商品フォームの保存とは衝突しない。
 */
class ProductImageManagementController extends Controller
{
    public const MAX_IMAGES = 5;

    private const LIMIT_MESSAGE = '商品画像は5枚以内で登録してください。';

    public function __construct(private StoreProductImageFile $files) {}

    public function store(ProductImageFileRequest $request, Product $product): JsonResponse
    {
        $this->ensureEditable($product);
        if ($product->images()->count() >= self::MAX_IMAGES) {
            throw ValidationException::withMessages(['file' => self::LIMIT_MESSAGE]);
        }
        $prepared = $this->files->handle($request->file('file'), 'file');

        try {
            $image = DB::transaction(function () use ($product, $prepared): ProductImage {
                $locked = $this->lock($product);
                if ($locked->images()->count() >= self::MAX_IMAGES) {
                    throw ValidationException::withMessages(['file' => self::LIMIT_MESSAGE]);
                }

                return $locked->images()->create([...$prepared, 'sort_order' => ((int) $locked->images()->max('sort_order')) + 1]);
            }, 3);
        } catch (Throwable $exception) {
            $this->files->delete($prepared['disk'], $prepared['path'], $prepared['thumbnail_path']);
            throw $exception;
        }

        return response()->json(['image' => $image->fresh()], 201);
    }

    public function replace(ProductImageFileRequest $request, Product $product, ProductImage $image): JsonResponse
    {
        $this->ensureEditable($product);
        $prepared = $this->files->handle($request->file('file'), 'file');

        try {
            $old = DB::transaction(function () use ($product, $image, $prepared): array {
                $this->lock($product);
                $current = ProductImage::whereKey($image->id)->where('product_id', $product->id)->lockForUpdate()->firstOrFail();
                $old = [$current->disk, $current->path, $current->thumbnail_path];
                $current->fill($prepared)->save();

                return $old;
            }, 3);
        } catch (Throwable $exception) {
            $this->files->delete($prepared['disk'], $prepared['path'], $prepared['thumbnail_path']);
            throw $exception;
        }
        $this->files->delete(...$old);

        return response()->json(['image' => $image->fresh()]);
    }

    public function update(Request $request, Product $product, ProductImage $image): JsonResponse
    {
        $this->ensureEditable($product);
        $data = $request->validate(
            ['alt_text' => ['nullable', 'string', 'max:200']],
            ['alt_text.string' => '画像の説明は文字で入力してください。', 'alt_text.max' => '画像の説明は200文字以内で入力してください。'],
        );
        $image->update(['alt_text' => $data['alt_text'] ?? null]);

        return response()->json(['image' => $image->fresh()]);
    }

    public function reorder(Request $request, Product $product): Response
    {
        $this->ensureEditable($product);
        $data = $request->validate(
            ['ids' => ['required', 'array', 'list', 'max:'.self::MAX_IMAGES], 'ids.*' => ['integer', 'distinct']],
            ['ids.*' => '画像の並び順を保存できませんでした。ページを再読み込みしてください。'],
        );
        $ids = array_map('intval', $data['ids']);

        DB::transaction(function () use ($product, $ids): void {
            $locked = $this->lock($product);
            $images = $locked->images()->lockForUpdate()->get()->keyBy('id');
            $current = $images->keys()->sort()->values()->all();
            $requested = collect($ids)->sort()->values()->all();
            if ($current !== $requested) {
                throw ValidationException::withMessages(['ids' => '画像が別の画面で変更されています。ページを再読み込みしてください。']);
            }
            foreach ($ids as $position => $id) {
                $images->get($id)->update(['sort_order' => $position]);
            }
        }, 3);

        return response()->noContent();
    }

    public function destroy(Product $product, ProductImage $image): Response
    {
        $this->ensureEditable($product);
        $deleted = DB::transaction(function () use ($product, $image): ?array {
            $this->lock($product);
            $current = ProductImage::whereKey($image->id)->where('product_id', $product->id)->lockForUpdate()->first();
            if ($current === null) {
                return null;
            }
            $current->delete();

            return [$current->disk, $current->path, $current->thumbnail_path];
        }, 3);
        if ($deleted !== null) {
            $this->files->delete(...$deleted);
        }

        return response()->noContent();
    }

    /**
     * 同じ商品への画像操作を順番に処理し、枚数の上限や並び順が食い違わないようにする。
     */
    private function lock(Product $product): Product
    {
        return Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
    }

    private function ensureEditable(Product $product): void
    {
        abort_unless(in_array($product->registration_status, ['provisional', 'confirmed'], true), 404);
    }
}
