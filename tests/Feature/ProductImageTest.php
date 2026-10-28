<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function input(?Product $product = null, array $overrides = []): array
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

    private function existingImage(Product $product, int $sort = 0): ProductImage
    {
        $image = ProductImage::factory()->for($product)->create(['sort_order' => $sort]);
        Storage::disk('product_images')->put($image->path, 'original image');
        Storage::disk('product_images')->put($image->thumbnail_path, 'original thumbnail');

        return $image;
    }

    #[TestWith(['jpg'])]
    #[TestWith(['png'])]
    #[TestWith(['webp'])]
    public function test_admin_uploads_image_and_gets_resized_webp_and_thumbnail(string $format): void
    {
        Storage::fake('product_images');
        $file = UploadedFile::fake()->image('photo.'.$format, 2400, 1200);

        $this->actingAs(User::factory()->admin()->create())->post('/admin/products', $this->input(null, [
            'images' => [['file' => $file, 'alt_text' => '商品の正面']],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $image = ProductImage::firstOrFail();
        $this->assertDatabaseHas('product_images', ['id' => $image->id, 'width' => 1600, 'height' => 800, 'sort_order' => 0, 'mime_type' => 'image/webp', 'alt_text' => '商品の正面']);
        $full = getimagesizefromstring(Storage::disk('product_images')->get($image->path));
        $thumb = getimagesizefromstring(Storage::disk('product_images')->get($image->thumbnail_path));
        $this->assertSame([1600, 800, 'image/webp'], [$full[0], $full[1], $full['mime']]);
        $this->assertSame([400, 200], [$thumb[0], $thumb[1]]);
        $this->assertArrayNotHasKey('path', $image->toArray());
        $this->assertArrayNotHasKey('disk', $image->toArray());
    }

    public function test_replace_reorder_and_remove_preserve_positions_and_delete_old_files(): void
    {
        Storage::fake('product_images');
        $product = Product::factory()->create();
        $first = $this->existingImage($product);
        $second = $this->existingImage($product, 1);
        $third = $this->existingImage($product, 2);
        $oldUrl = $second->url;

        $this->actingAs(User::factory()->admin()->create())->post('/admin/products/'.$product->id, $this->input($product, [
            '_method' => 'put',
            'images' => [
                ['id' => $second->id, 'file' => UploadedFile::fake()->image('replacement.jpg')],
                ['id' => $first->id, 'alt_text' => '背面'],
            ],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([$second->id, $first->id], $product->images()->pluck('id')->all());
        $this->assertDatabaseHas('product_images', ['id' => $first->id, 'alt_text' => '背面', 'sort_order' => 1]);
        $this->assertDatabaseMissing('product_images', ['id' => $third->id]);
        Storage::disk('product_images')->assertMissing([$second->path, $second->thumbnail_path, $third->path, $third->thumbnail_path]);
        Storage::disk('product_images')->assertExists([$first->path, $second->fresh()->path]);
        $this->assertNotSame($oldUrl, $second->fresh()->url);
        $this->assertSame(1, $product->fresh()->lock_version);
    }

    public function test_empty_multipart_selection_removes_all_images_and_categories(): void
    {
        Storage::fake('product_images');
        $product = Product::factory()->create();
        $image = $this->existingImage($product);
        $product->categories()->attach(Category::factory()->create());
        $data = $this->input($product);
        unset($data['images']);

        $this->actingAs(User::factory()->admin()->create())->post('/admin/products/'.$product->id, [...$data, '_method' => 'put'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame(0, $product->categories()->count());
        Storage::disk('product_images')->assertMissing([$image->path, $image->thumbnail_path]);
    }

    public function test_legacy_product_save_without_image_fields_preserves_images(): void
    {
        Storage::fake('product_images');
        $product = Product::factory()->create();
        $image = $this->existingImage($product);
        $data = $this->input($product);
        unset($data['images'], $data['images_present']);

        $this->actingAs(User::factory()->admin()->create())->put('/admin/products/'.$product->id, $data)->assertSessionHasNoErrors();

        $this->assertModelExists($image);
        Storage::disk('product_images')->assertExists([$image->path, $image->thumbnail_path]);
    }

    public function test_stale_save_keeps_original_and_cleans_new_uploads(): void
    {
        Storage::fake('product_images');
        $product = Product::factory()->create(['lock_version' => 2]);
        $image = $this->existingImage($product);

        $this->actingAs(User::factory()->admin()->create())->put('/admin/products/'.$product->id, $this->input($product, [
            'name' => '保存されない名前', 'lock_version' => 1,
            'images' => [['id' => $image->id, 'file' => UploadedFile::fake()->image('new.png')]],
        ]))->assertSessionHasErrors('lock_version');

        $this->assertSame($image->path, $image->fresh()->path);
        $this->assertSame($product->name, $product->fresh()->name);
        $this->assertCount(2, Storage::disk('product_images')->allFiles());
    }

    public function test_database_failure_after_file_preparation_rolls_back_product_and_files(): void
    {
        Storage::fake('product_images');
        $product = Product::factory()->create();
        $image = $this->existingImage($product);

        $this->actingAs(User::factory()->admin()->create())->put('/admin/products/'.$product->id, $this->input($product, [
            'name' => '保存されない名前',
            'images' => [
                ['id' => $image->id, 'file' => UploadedFile::fake()->image('new.png')],
                ['id' => $image->id],
            ],
        ]))->assertSessionHasErrors('images');

        $this->assertSame($image->path, $image->fresh()->path);
        $this->assertSame($product->name, $product->fresh()->name);
        $this->assertSame(0, $product->fresh()->lock_version);
        $this->assertCount(2, Storage::disk('product_images')->allFiles());
    }

    public function test_storage_failure_does_not_change_product_or_original_image(): void
    {
        Storage::fake('product_images');
        $product = Product::factory()->create();
        $image = $this->existingImage($product);
        config(['filesystems.product_image_disk' => 'broken_image_disk']);

        $this->actingAs(User::factory()->admin()->create())->put('/admin/products/'.$product->id, $this->input($product, [
            'name' => '保存されない名前',
            'images' => [['id' => $image->id, 'file' => UploadedFile::fake()->image('new.png')]],
        ]))->assertSessionHasErrors('images.0.file');

        $this->assertSame($image->path, $image->fresh()->path);
        $this->assertSame($product->name, $product->fresh()->name);
        Storage::disk('product_images')->assertExists([$image->path, $image->thumbnail_path]);
    }

    public function test_another_products_image_cannot_be_attached_or_deleted(): void
    {
        Storage::fake('product_images');
        $product = Product::factory()->create();
        $foreign = $this->existingImage(Product::factory()->create());

        $this->actingAs(User::factory()->admin()->create())->put('/admin/products/'.$product->id, $this->input($product, [
            'images' => [['id' => $foreign->id]],
        ]))->assertSessionHasErrors('images.0.id');

        $this->assertModelExists($foreign);
        $this->assertSame(0, $product->images()->count());
        Storage::disk('product_images')->assertExists([$foreign->path, $foreign->thumbnail_path]);
    }

    public function test_six_images_are_rejected_without_writing_files(): void
    {
        Storage::fake('product_images');
        $images = array_map(fn () => ['file' => UploadedFile::fake()->image('new.png')], range(1, 6));

        $this->actingAs(User::factory()->admin()->create())->post('/admin/products', $this->input(null, ['images' => $images]))
            ->assertSessionHasErrors(['images' => '商品画像は5枚以内で登録してください。']);

        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame([], Storage::disk('product_images')->allFiles());
    }

    #[TestWith(['gif'])]
    #[TestWith(['svg'])]
    #[TestWith(['fake'])]
    #[TestWith(['size'])]
    #[TestWith(['dimensions'])]
    public function test_invalid_upload_is_rejected(string $case): void
    {
        Storage::fake('product_images');
        $file = match ($case) {
            'gif' => UploadedFile::fake()->image('test.gif'),
            'svg' => UploadedFile::fake()->createWithContent('test.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'fake' => UploadedFile::fake()->createWithContent('test.jpg', '<?php echo "not an image";'),
            'size' => UploadedFile::fake()->image('test.jpg')->size(5121),
            'dimensions' => UploadedFile::fake()->image('test.png', 6001, 1),
        };

        $this->actingAs(User::factory()->admin()->create())->post('/admin/products', $this->input(null, ['images' => [['file' => $file]]]))
            ->assertSessionHasErrors('images.0.file');

        $this->assertDatabaseCount('products', 0);
        $this->assertSame([], Storage::disk('product_images')->allFiles());
    }

    public function test_new_image_requires_a_file(): void
    {
        Storage::fake('product_images');

        $this->actingAs(User::factory()->admin()->create())->post('/admin/products', $this->input(null, ['images' => [['alt_text' => '画像なし']]]))
            ->assertSessionHasErrors(['images.0.file' => '画像ファイルを選択してください。']);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_guests_customers_and_unverified_admins_cannot_edit_images(): void
    {
        Storage::fake('product_images');
        $product = Product::factory()->create();
        $image = $this->existingImage($product);
        $data = $this->input($product);

        $this->put('/admin/products/'.$product->id, $data)->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->put('/admin/products/'.$product->id, $data)->assertForbidden();
        $this->actingAs(User::factory()->admin()->unverified()->create())->put('/admin/products/'.$product->id, $data)->assertRedirect('/email/verify');

        $this->assertModelExists($image);
        Storage::disk('product_images')->assertExists([$image->path, $image->thumbnail_path]);
    }

    public function test_private_images_require_verified_admin_and_publication_changes_apply_immediately(): void
    {
        Storage::fake('product_images');
        $product = Product::factory()->create();
        $image = $this->existingImage($product);
        $url = $image->url;

        $this->get($url)->assertNotFound();
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        $this->actingAs(User::factory()->admin()->unverified()->create())->get($url)->assertNotFound();
        $this->actingAs(User::factory()->admin()->create())->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->actingAs(User::factory()->create());
        $product->update(['registration_status' => 'confirmed', 'publication_status' => 'public']);
        $response = $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get($image->thumbnail_url)->assertOk();
        $product->update(['publication_status' => 'private']);
        $this->get($url)->assertNotFound();
        $this->get('/product-images/'.$image->id.'/original')->assertNotFound();
    }

    public function test_public_images_are_visible_to_guests_and_missing_files_return_404(): void
    {
        Storage::fake('product_images');
        $product = Product::factory()->create(['registration_status' => 'confirmed', 'publication_status' => 'public']);
        $image = $this->existingImage($product);

        $this->get($image->url)->assertOk();
        Storage::disk('product_images')->delete($image->path);
        $this->get($image->url)->assertNotFound();
    }

    public function test_orientation_is_corrected_metadata_removed_and_small_image_not_upscaled(): void
    {
        Storage::fake('product_images');
        $jpeg = UploadedFile::fake()->image('camera.jpg', 80, 40)->getContent();
        $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvVv', 0x0112, 3, 1, 6)."\0\0".pack('V', 0);
        $jpeg = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
        $file = UploadedFile::fake()->createWithContent('camera.jpg', $jpeg);

        $this->actingAs(User::factory()->admin()->create())->post('/admin/products', $this->input(null, ['images' => [['file' => $file]]]))
            ->assertSessionHasNoErrors();

        $image = ProductImage::firstOrFail();
        $this->assertSame([40, 80], [$image->width, $image->height]);
        $decoded = new \Imagick;
        $decoded->readImageBlob(Storage::disk('product_images')->get($image->path));
        $this->assertSame([], $decoded->getImageProfiles());
        $decoded->clear();
    }

    public function test_five_images_can_be_saved_and_sixth_retained_entry_is_rejected(): void
    {
        Storage::fake('product_images');
        $this->actingAs(User::factory()->admin()->create());
        $images = array_map(fn () => ['file' => UploadedFile::fake()->image('new.png')], range(1, 5));

        $this->post('/admin/products', $this->input(null, ['images' => $images]))->assertSessionHasNoErrors();

        $product = Product::firstOrFail();
        $this->assertSame(5, $product->images()->count());
        $this->assertCount(10, Storage::disk('product_images')->allFiles());
        $retained = $product->images->map(fn ($image) => ['id' => $image->id])->all();
        $this->put('/admin/products/'.$product->id, $this->input($product, [
            'images' => [...$retained, ['file' => UploadedFile::fake()->image('sixth.png')]],
        ]))->assertSessionHasErrors('images');
        $this->assertCount(10, Storage::disk('product_images')->allFiles());
    }

    public function test_pixel_limit_is_checked_before_decoding(): void
    {
        Storage::fake('product_images');
        $file = UploadedFile::fake()->image('large.png', 5000, 4001);

        $this->actingAs(User::factory()->admin()->create())->post('/admin/products', $this->input(null, ['images' => [['file' => $file]]]))
            ->assertSessionHasErrors(['images.0.file' => '画像は2000万画素以内にしてください。']);

        $this->assertDatabaseCount('products', 0);
        $this->assertSame([], Storage::disk('product_images')->allFiles());
    }

    public function test_images_are_ordered_in_shop_cart_checkout_and_admin_props(): void
    {
        $product = Product::factory()->create(['registration_status' => 'confirmed', 'publication_status' => 'public', 'default_sale_price' => 10]);
        $last = ProductImage::factory()->for($product)->create(['sort_order' => 1]);
        $first = ProductImage::factory()->for($product)->create(['sort_order' => 0]);

        $this->get('/shop')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.images.0.id', $first->id)
            ->where('products.data.0.images.1.id', $last->id)
            ->missing('products.data.0.images.0.path'));
        $this->withSession(['cart' => [$product->id => '1']])->get('/cart')
            ->assertInertia(fn (Assert $page) => $page->where('items.0.product.images.0.id', $first->id));
        $this->actingAs(User::factory()->create())->get('/checkout')
            ->assertInertia(fn (Assert $page) => $page->where('items.0.product.images.0.id', $first->id));
        $this->actingAs(User::factory()->admin()->create())->get('/admin/products/'.$product->id.'/edit')
            ->assertInertia(fn (Assert $page) => $page->where('product.images.0.id', $first->id));
    }
}
