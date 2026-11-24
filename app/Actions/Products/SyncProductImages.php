<?php

namespace App\Actions\Products;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductImageUpload;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class SyncProductImages
{
    private array $prepared = [];

    private array $newFiles = [];

    private array $obsoleteFiles = [];

    public function __construct(private StoreProductImageFile $storeProductImageFile) {}

    /**
     * Prepare files before locking the product; remove old files only after commit.
     * 事前にアップロード済みの画像（upload_id）は、商品のトランザクション内で商品画像へ移す。
     */
    public function handle(?array $images, Closure $save): mixed
    {
        $this->prepared = [];
        $this->newFiles = [];
        $this->obsoleteFiles = [];
        try {
            foreach ($images ?? [] as $index => $image) {
                $this->prepared[$index] = isset($image['file']) ? $this->prepare($image['file'], $index) : [];
            }
            $result = $save($this);
        } catch (Throwable $exception) {
            $this->deleteFiles($this->newFiles);
            throw $exception;
        }

        $obsolete = $this->obsoleteFiles;
        DB::afterCommit(fn () => $this->deleteFiles($obsolete));

        return $result;
    }

    /** Called inside the product transaction, after acquiring its row lock. */
    public function sync(Product|ProductGroup $product, ?array $images): void
    {
        if ($images === null) {
            return;
        }
        $existing = $product->images()->lockForUpdate()->get()->keyBy('id');
        $retained = [];
        foreach ($images as $index => $input) {
            $id = $input['id'] ?? null;
            if ($id && (! $existing->has($id) || in_array((int) $id, $retained, true))) {
                throw ValidationException::withMessages(['images' => 'この商品の画像を選び直してください。']);
            }
            $image = $id ? $existing->get($id) : $product->images()->make();
            $prepared = $this->prepared[$index] ?? [];
            if ($prepared === [] && isset($input['upload_id'])) {
                $prepared = $this->claimUpload((int) $input['upload_id'], $index);
            }
            if (! $id && $prepared === []) {
                throw ValidationException::withMessages(["images.$index.file" => '画像ファイルを選択してください。']);
            }
            if ($id && $prepared !== []) {
                $this->obsoleteFiles[] = [$image->disk, $image->path, $image->thumbnail_path];
            }
            $image->fill([...$prepared, 'sort_order' => $index, 'alt_text' => $input['alt_text'] ?? null]);
            $product->images()->save($image);
            $retained[] = (int) $image->id;
        }
        foreach ($existing as $image) {
            if (! in_array((int) $image->id, $retained, true)) {
                $this->obsoleteFiles[] = [$image->disk, $image->path, $image->thumbnail_path];
                $image->delete();
            }
        }
    }

    /**
     * アップロード済みの画像を商品画像用に取り出し、アップロードのレコードを削除する。
     * ファイルはそのまま商品画像が引き継ぐ。トランザクションが巻き戻ればレコードも戻るので、再保存できる。
     *
     * @return array{disk: string, path: string, thumbnail_path: string, mime_type: string, size_bytes: int, width: int, height: int}
     */
    private function claimUpload(int $uploadId, int $index): array
    {
        $upload = ProductImageUpload::whereKey($uploadId)->lockForUpdate()->first();
        if ($upload === null) {
            throw ValidationException::withMessages(["images.$index.upload_id" => '画像をアップロードし直してください。']);
        }
        $attributes = $upload->imageAttributes();
        $upload->delete();

        return $attributes;
    }

    private function prepare(UploadedFile $file, int $index): array
    {
        $prepared = $this->storeProductImageFile->handle($file, "images.$index.file");
        $this->newFiles[] = [$prepared['disk'], $prepared['path'], $prepared['thumbnail_path']];

        return $prepared;
    }

    private function deleteFiles(array $files): void
    {
        foreach ($files as [$disk, $path, $thumbnail]) {
            $this->storeProductImageFile->delete($disk, $path, $thumbnail);
        }
    }
}
