<?php

namespace App\Http\Controllers;

use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImageController extends Controller
{
    public function show(Request $request, ProductImage $productImage, string $variant): StreamedResponse
    {
        $product = $productImage->product;
        $admin = $request->user()?->isAdmin() && $request->user()->hasVerifiedEmail();
        abort_unless($admin || ($product->registration_status === 'confirmed' && $product->publication_status === 'public'), 404);
        $disk = Storage::disk($productImage->disk);
        $path = $variant === 'thumbnail' ? $productImage->thumbnail_path : $productImage->path;
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
