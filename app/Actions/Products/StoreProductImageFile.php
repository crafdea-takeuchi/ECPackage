<?php

namespace App\Actions\Products;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class StoreProductImageFile
{
    /**
     * 画像をWebPの本体（最大1600px）とサムネイル（最大400px）に変換して保存する。
     * 書き込みに失敗した場合は、このメソッドで書いたファイルを削除してから検証エラーにする。
     *
     * @return array{disk: string, path: string, thumbnail_path: string, mime_type: string, size_bytes: int, width: int, height: int}
     */
    public function handle(UploadedFile $file, string $errorKey): array
    {
        $disk = config('filesystems.product_image_disk', 'product_images');
        $key = (string) Str::uuid();
        $path = $key.'.webp';
        $thumbnailPath = $key.'-thumb.webp';
        try {
            $dimensions = @getimagesize($file->getRealPath());
            if (! $dimensions || $dimensions[0] * $dimensions[1] > 20000000) {
                throw ValidationException::withMessages([$errorKey => '画像は2000万画素以内にしてください。']);
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
            if (! Storage::disk($disk)->put($path, $full) || ! Storage::disk($disk)->put($thumbnailPath, $thumbnail)) {
                throw new \RuntimeException('Product image write failed.');
            }

            return ['disk' => $disk, 'path' => $path, 'thumbnail_path' => $thumbnailPath, 'mime_type' => 'image/webp', 'size_bytes' => strlen($full), 'width' => $width, 'height' => $height];
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->delete($disk, $path, $thumbnailPath);
            throw ValidationException::withMessages([$errorKey => '画像を保存できませんでした。ファイルを確認して再度お試しください。']);
        }
    }

    /**
     * 保存済みの本体とサムネイルを削除する。削除に失敗しても例外にはせず記録だけ残す。
     */
    public function delete(string $disk, string $path, string $thumbnailPath): void
    {
        try {
            if (! Storage::disk($disk)->delete([$path, $thumbnailPath])) {
                throw new \RuntimeException('Product image cleanup failed: '.$path);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function resize(\Imagick $image, int $max): void
    {
        if ($image->getImageWidth() > $max || $image->getImageHeight() > $max) {
            $image->thumbnailImage($max, $max, true);
        }
    }
}
