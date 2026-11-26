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
     * 保存できる形式と、保存時の拡張子・MIMEタイプ。
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const FORMATS = [
        'JPEG' => ['jpg', 'image/jpeg'],
        'PNG' => ['png', 'image/png'],
        'GIF' => ['gif', 'image/gif'],
        'WEBP' => ['webp', 'image/webp'],
    ];

    /**
     * 画像を元の形式・元の大きさのまま保存する。撮影情報（位置情報などのメタデータ）だけを取り除く。
     * メタデータと一緒に向きの情報も消えるため、向きの情報がある写真は見た目が変わらないよう画素を回転してから保存する。
     * 縮小版は作らず、サムネイルにも同じファイルを使う。
     * 書き込みに失敗した場合は、このメソッドで書いたファイルを削除してから検証エラーにする。
     *
     * @return array{disk: string, path: string, thumbnail_path: string, mime_type: string, size_bytes: int, width: int, height: int}
     */
    public function handle(UploadedFile $file, string $errorKey): array
    {
        $disk = config('filesystems.product_image_disk', 'product_images');
        $path = null;
        try {
            $dimensions = @getimagesize($file->getRealPath());
            if (! $dimensions || $dimensions[0] * $dimensions[1] > 20000000) {
                throw ValidationException::withMessages([$errorKey => '画像は2000万画素以内にしてください。']);
            }
            $image = new \Imagick;
            try {
                $image->readImage($file->getRealPath());
                [$extension, $mimeType] = self::FORMATS[strtoupper($image->getImageFormat())]
                    ?? throw ValidationException::withMessages([$errorKey => 'JPEG・PNG・WebP・GIFの画像を選択してください。']);
                $animated = $image->getNumberImages() > 1;
                if (! $animated) {
                    $this->applyOrientation($image);
                    $width = $image->getImageWidth();
                    $height = $image->getImageHeight();
                } else {
                    [$width, $height] = $dimensions;
                }
                $this->keepQuality($image);
                foreach ($image as $frame) {
                    $frame->stripImage();
                }
                $contents = $animated ? $image->getImagesBlob() : $image->getImageBlob();
            } finally {
                $image->clear();
            }
            $path = Str::uuid().'.'.$extension;
            if (! Storage::disk($disk)->put($path, $contents)) {
                throw new \RuntimeException('Product image write failed.');
            }

            return ['disk' => $disk, 'path' => $path, 'thumbnail_path' => $path, 'mime_type' => $mimeType, 'size_bytes' => strlen($contents), 'width' => $width, 'height' => $height];
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            if ($path !== null) {
                $this->delete($disk, $path, $path);
            }
            throw ValidationException::withMessages([$errorKey => '画像を保存できませんでした。ファイルを確認して再度お試しください。']);
        }
    }

    /**
     * 保存済みの本体とサムネイルを削除する。削除に失敗しても例外にはせず記録だけ残す。
     */
    public function delete(string $disk, string $path, string $thumbnailPath): void
    {
        try {
            if (! Storage::disk($disk)->delete(array_values(array_unique([$path, $thumbnailPath])))) {
                throw new \RuntimeException('Product image cleanup failed: '.$path);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * 向きの情報が付いている写真だけ、表示される向きに合わせて画素を回転する。
     */
    private function applyOrientation(\Imagick $image): void
    {
        if (! in_array($image->getImageOrientation(), [\Imagick::ORIENTATION_UNDEFINED, \Imagick::ORIENTATION_TOPLEFT], true)) {
            $image->autoOrient();
        }
    }

    /**
     * JPEG・WebPは保存し直すときに画質が落ちないよう、元の画質の設定を引き継ぐ。
     */
    private function keepQuality(\Imagick $image): void
    {
        $quality = $image->getImageCompressionQuality();
        if ($quality > 0) {
            $image->setImageCompressionQuality($quality);
        }
    }
}
