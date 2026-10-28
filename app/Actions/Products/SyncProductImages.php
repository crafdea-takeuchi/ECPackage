<?php

namespace App\Actions\Products;

use App\Models\Product;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SyncProductImages
{
    private array $prepared = [];

    private array $newFiles = [];

    private array $obsoleteFiles = [];

    /** Prepare files before locking the product; remove old files only after commit. */
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
    public function sync(Product $product, ?array $images): void
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
            $prepared = $this->prepared[$index];
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

    private function prepare(UploadedFile $file, int $index): array
    {
        try {
            $dimensions = @getimagesize($file->getRealPath());
            if (! $dimensions || $dimensions[0] * $dimensions[1] > 20000000) {
                throw ValidationException::withMessages(["images.$index.file" => '画像は2000万画素以内にしてください。']);
            }
            $image = new \Imagick;
            try {
                $image->readImage($file->getRealPath().'[0]');
                $image->autoOrient();
                $image->stripImage();
                $image->setImageFormat('webp');
                $image->setImageCompressionQuality(85);
                $this->resize($image, 1600);
                $full = $image->getImageBlob();
                $width = $image->getImageWidth();
                $height = $image->getImageHeight();
                $this->resize($image, 400);
                $thumbnail = $image->getImageBlob();
            } finally {
                $image->clear();
            }
            $disk = config('filesystems.product_image_disk', 'product_images');
            $key = (string) Str::uuid();
            $path = $key.'.webp';
            $thumbnailPath = $key.'-thumb.webp';
            $this->newFiles[] = [$disk, $path, $thumbnailPath];
            if (! Storage::disk($disk)->put($path, $full) || ! Storage::disk($disk)->put($thumbnailPath, $thumbnail)) {
                throw new \RuntimeException('Product image write failed.');
            }

            return ['disk' => $disk, 'path' => $path, 'thumbnail_path' => $thumbnailPath, 'mime_type' => 'image/webp', 'size_bytes' => strlen($full), 'width' => $width, 'height' => $height];
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(["images.$index.file" => '画像を保存できませんでした。ファイルを確認して再度お試しください。']);
        }
    }

    private function resize(\Imagick $image, int $max): void
    {
        if ($image->getImageWidth() > $max || $image->getImageHeight() > $max) {
            $image->thumbnailImage($max, $max, true);
        }
    }

    private function deleteFiles(array $files): void
    {
        foreach ($files as [$disk, $path, $thumbnail]) {
            try {
                if (! Storage::disk($disk)->delete([$path, $thumbnail])) {
                    throw new \RuntimeException('Product image cleanup failed: '.$path);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
