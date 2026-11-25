<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductImageUpload;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageUploadTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('product_images');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function productInput(?Product $product = null, array $overrides = []): array
    {
        return array_replace([
            'name' => $product?->name ?? '画像テスト商品',
            'sku' => $product?->sku ?? '',
            'unit_id' => $product?->unit_id ?? Unit::factory()->create()->id,
            'registration_status' => $product?->registration_status ?? 'provisional',
            'publication_status' => $product?->publication_status ?? 'private',
            'default_sale_price' => $product?->default_sale_price,
            'lock_version' => $product?->lock_version ?? 0,
            'images_present' => true,
            'images' => [],
        ], $overrides);
    }

    private function upload(User $admin, string $name = 'photo.jpg'): int
    {
        return $this->actingAs($admin)
            ->postJson('/admin/product-image-uploads', ['file' => UploadedFile::fake()->image($name, 2400, 1200)])
            ->assertCreated()
            ->json('upload_id');
    }

    public function test_admin_uploads_one_image_and_gets_upload_id_with_converted_files(): void
    {
        $admin = User::factory()->admin()->create();

        $uploadId = $this->upload($admin);

        $upload = ProductImageUpload::findOrFail($uploadId);
        $this->assertSame($admin->id, $upload->created_by);
        $this->assertSame([1600, 800, 'image/webp'], [$upload->width, $upload->height, $upload->mime_type]);
        Storage::disk('product_images')->assertExists([$upload->path, $upload->thumbnail_path]);
        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_invalid_image_upload_returns_json_error_without_writing_files(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/admin/product-image-uploads', ['file' => UploadedFile::fake()->image('test.bmp')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'JPEG・PNG・WebP・GIFの画像を選択してください。']);

        $this->assertDatabaseCount('product_image_uploads', 0);
        $this->assertSame([], Storage::disk('product_images')->allFiles());
    }

    public function test_animated_gif_is_accepted_and_saved_as_still_webp_of_first_frame(): void
    {
        $animation = new \Imagick;
        foreach (['red', 'blue'] as $color) {
            $frame = new \Imagick;
            $frame->newImage(320, 240, new \ImagickPixel($color));
            $frame->setImageFormat('gif');
            $animation->addImage($frame);
        }
        $gif = $animation->getImagesBlob();

        $uploadId = $this->actingAs(User::factory()->admin()->create())
            ->postJson('/admin/product-image-uploads', ['file' => UploadedFile::fake()->createWithContent('animation.gif', $gif)])
            ->assertCreated()
            ->json('upload_id');

        $upload = ProductImageUpload::findOrFail($uploadId);
        $saved = new \Imagick;
        $saved->readImageBlob(Storage::disk('product_images')->get($upload->path));
        $this->assertSame(['WEBP', 1, 320, 240], [$saved->getImageFormat(), $saved->getNumberImages(), $upload->width, $upload->height]);
        $pixel = $saved->getImagePixelColor(10, 10)->getColor();
        $this->assertGreaterThan(200, $pixel['r'], '1フレーム目（赤）で保存される');
        $this->assertLessThan(60, $pixel['b']);
    }

    public function test_image_size_limit_is_five_million_bytes(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson('/admin/product-image-uploads', ['file' => UploadedFile::fake()->image('just-under.jpg')->size(4882)])
            ->assertCreated();
        $this->actingAs($admin)
            ->postJson('/admin/product-image-uploads', ['file' => UploadedFile::fake()->image('over.jpg')->size(4883)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => '画像は1枚5MB以内にしてください。']);
        $this->actingAs($admin)
            ->postJson('/admin/product-image-uploads', ['file' => UploadedFile::fake()->image('finder-5.1mb.jpg')->size(4981)])
            ->assertUnprocessable();

        $this->assertDatabaseCount('product_image_uploads', 1);
    }

    public function test_customers_and_guests_cannot_upload_images(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg');

        $this->postJson('/admin/product-image-uploads', ['file' => $file])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson('/admin/product-image-uploads', ['file' => $file])->assertForbidden();
        $this->assertDatabaseCount('product_image_uploads', 0);
    }

    public function test_saving_product_attaches_individually_uploaded_images_in_order(): void
    {
        $admin = User::factory()->admin()->create();
        $first = $this->upload($admin, 'first.jpg');
        $second = $this->upload($admin, 'second.png');
        $firstPath = ProductImageUpload::findOrFail($first)->path;

        $this->actingAs($admin)->post('/admin/products', $this->productInput(null, [
            'images' => [['upload_id' => $second, 'alt_text' => '背面'], ['upload_id' => $first, 'alt_text' => '正面']],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $product = Product::sole();
        $images = $product->images()->get();
        $this->assertSame(['背面', '正面'], $images->pluck('alt_text')->all());
        $this->assertSame($firstPath, $images[1]->path);
        Storage::disk('product_images')->assertExists($firstPath);
        $this->assertDatabaseCount('product_image_uploads', 0);
    }

    public function test_replacing_existing_image_with_upload_deletes_old_files_after_save(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create();
        $image = ProductImage::factory()->for($product)->create();
        Storage::disk('product_images')->put($image->path, 'original image');
        Storage::disk('product_images')->put($image->thumbnail_path, 'original thumbnail');
        $uploadId = $this->upload($admin);
        $uploadPath = ProductImageUpload::findOrFail($uploadId)->path;

        $this->actingAs($admin)->put('/admin/products/'.$product->id, $this->productInput($product, [
            'images' => [['id' => $image->id, 'upload_id' => $uploadId]],
        ]))->assertSessionHasNoErrors();

        $this->assertSame($uploadPath, $image->fresh()->path);
        Storage::disk('product_images')->assertMissing([$image->path, $image->thumbnail_path]);
        $this->assertDatabaseCount('product_image_uploads', 0);
    }

    public function test_failed_product_save_keeps_upload_so_it_can_be_saved_again(): void
    {
        $admin = User::factory()->admin()->create();
        $uploadId = $this->upload($admin);
        $input = $this->productInput(null, ['images' => [['upload_id' => $uploadId]]]);

        $this->actingAs($admin)->post('/admin/products', [...$input, 'name' => ''])->assertSessionHasErrors('name');
        $this->assertModelExists(ProductImageUpload::findOrFail($uploadId));

        $this->actingAs($admin)->post('/admin/products', $input)->assertSessionHasNoErrors();
        $this->assertSame(1, ProductImage::count());
    }

    public function test_upload_of_another_admin_or_used_upload_cannot_be_attached(): void
    {
        $admin = User::factory()->admin()->create();
        $othersUpload = $this->upload(User::factory()->admin()->create());

        $this->actingAs($admin)->post('/admin/products', $this->productInput(null, ['images' => [['upload_id' => $othersUpload]]]))
            ->assertSessionHasErrors(['images.0.upload_id' => '画像をアップロードし直してください。']);
        $this->actingAs($admin)->post('/admin/products', $this->productInput(null, ['images' => [['upload_id' => 999999]]]))
            ->assertSessionHasErrors(['images.0.upload_id' => '画像をアップロードし直してください。']);

        $this->assertDatabaseCount('products', 0);
        $this->assertModelExists(ProductImageUpload::findOrFail($othersUpload));
    }

    public function test_same_upload_cannot_be_used_twice_in_one_save(): void
    {
        $admin = User::factory()->admin()->create();
        $uploadId = $this->upload($admin);

        $this->actingAs($admin)->post('/admin/products', $this->productInput(null, [
            'images' => [['upload_id' => $uploadId], ['upload_id' => $uploadId]],
        ]))->assertSessionHasErrors(['images.0.upload_id' => '同じ画像が重複しています。']);

        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_product_group_images_do_not_accept_upload_ids(): void
    {
        $admin = User::factory()->admin()->create();
        $uploadId = $this->upload($admin);

        $this->actingAs($admin)->post('/admin/product-groups', [
            'name' => '基本商品', 'attributes' => [], 'images_present' => true, 'images' => [['upload_id' => $uploadId]],
        ])->assertSessionHasErrors('images.0.upload_id');

        $this->assertModelExists(ProductImageUpload::findOrFail($uploadId));
    }

    public function test_pruning_removes_only_stale_uploads_and_their_files(): void
    {
        $stale = ProductImageUpload::factory()->create(['created_at' => now()->subHours(ProductImageUpload::RETENTION_HOURS + 1)]);
        $fresh = ProductImageUpload::factory()->create();
        foreach ([$stale, $fresh] as $upload) {
            Storage::disk('product_images')->put($upload->path, 'image');
            Storage::disk('product_images')->put($upload->thumbnail_path, 'thumbnail');
        }

        $this->artisan('model:prune', ['--model' => [ProductImageUpload::class]])->assertSuccessful();

        $this->assertModelMissing($stale);
        $this->assertModelExists($fresh);
        Storage::disk('product_images')->assertMissing([$stale->path, $stale->thumbnail_path]);
        Storage::disk('product_images')->assertExists([$fresh->path, $fresh->thumbnail_path]);
    }
}
