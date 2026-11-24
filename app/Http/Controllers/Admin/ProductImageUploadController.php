<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Products\StoreProductImageFile;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductImageUploadRequest;
use App\Models\ProductImageUpload;
use Illuminate\Http\JsonResponse;
use Throwable;

class ProductImageUploadController extends Controller
{
    /**
     * 販売商品の画像を1枚だけ受け取り、変換して一時保存する。商品の保存時に upload_id で紐付ける。
     */
    public function store(ProductImageUploadRequest $request, StoreProductImageFile $storeProductImageFile): JsonResponse
    {
        $stored = $storeProductImageFile->handle($request->file('file'), 'file');
        try {
            $upload = ProductImageUpload::create([...$stored, 'created_by' => $request->user()->id]);
        } catch (Throwable $exception) {
            $storeProductImageFile->delete($stored['disk'], $stored['path'], $stored['thumbnail_path']);
            throw $exception;
        }

        return response()->json(['upload_id' => $upload->id], 201);
    }
}
