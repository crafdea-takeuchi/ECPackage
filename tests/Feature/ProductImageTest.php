<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('product_images');
    }

    private function existingImage(Product $product, int $sort = 0): ProductImage
    {
        $image = ProductImage::factory()->for($product)->create(['sort_order' => $sort]);
        Storage::disk('product_images')->put($image->path, 'original image');
        Storage::disk('product_images')->put($image->thumbnail_path, 'original thumbnail');

        return $image;
    }

    private function upload(Product $product, UploadedFile $file, ?User $admin = null): TestResponse
    {
        return $this->actingAs($admin ?? User::factory()->admin()->create())
            ->postJson(route('admin.products.images.store', $product), ['file' => $file]);
    }

    /**
     * @return array<string, mixed>
     */
    private function productInput(Product $product, array $overrides = []): array
    {
        return array_replace([
            'name' => $product->name,
            'sku' => $product->sku,
            'unit_id' => $product->unit_id,
            'registration_status' => $product->registration_status,
            'publication_status' => $product->publication_status,
            'default_sale_price' => $product->default_sale_price,
            'lock_version' => $product->lock_version ?? 0,
            'images_present' => true,
        ], $overrides);
    }

    #[TestWith(['jpg', 'image/jpeg'])]
    #[TestWith(['png', 'image/png'])]
    #[TestWith(['webp', 'image/webp'])]
    #[TestWith(['gif', 'image/gif'])]
    public function test_uploaded_image_is_attached_immediately_in_its_original_format_and_size(string $format, string $mimeType): void
    {
        $product = Product::factory()->create();

        $response = $this->upload($product, UploadedFile::fake()->image('photo.'.$format, 2400, 1200))->assertCreated();

        $image = $product->images()->sole();
        $response->assertJsonPath('image.id', $image->id)
            ->assertJsonPath('image.thumbnail_url', $image->thumbnail_url)
            ->assertJsonMissingPath('image.path')
            ->assertJsonMissingPath('image.disk');
        $this->assertSame([2400, 1200, $mimeType], [$image->width, $image->height, $image->mime_type]);
        $this->assertStringEndsWith('.'.$format, $image->path);
        $this->assertSame($image->path, $image->thumbnail_path, '縮小版は作らず同じファイルを使う');
        $stored = getimagesizefromstring(Storage::disk('product_images')->get($image->path));
        $this->assertSame([2400, 1200, $mimeType], [$stored[0], $stored[1], $stored['mime']]);
        $this->assertCount(1, Storage::disk('product_images')->allFiles());
        $this->actingAs(User::factory()->admin()->create())->get($image->url)->assertOk()->assertHeader('Content-Type', $mimeType);
        $this->assertSame(0, $product->fresh()->lock_version, '画像の操作では商品フォームの保存と衝突させない');
    }

    public function test_animated_gif_keeps_its_animation(): void
    {
        $animation = new \Imagick;
        foreach (['red', 'blue'] as $color) {
            $frame = new \Imagick;
            $frame->newImage(320, 240, new \ImagickPixel($color));
            $frame->setImageFormat('gif');
            $animation->addImage($frame);
        }
        $product = Product::factory()->create();

        $this->upload($product, UploadedFile::fake()->createWithContent('animation.gif', $animation->getImagesBlob()))->assertCreated();

        $image = $product->images()->sole();
        $saved = new \Imagick;
        $saved->readImageBlob(Storage::disk('product_images')->get($image->path));
        $this->assertSame(['GIF', 2, 320, 240, 'image/gif'], [$saved->getImageFormat(), $saved->getNumberImages(), $image->width, $image->height, $image->mime_type]);
        $saved->setIteratorIndex(1);
        $pixel = $saved->getImagePixelColor(10, 10)->getColor();
        $this->assertGreaterThan(200, $pixel['b'], '2フレーム目（青）も残る');
    }

    public function test_uploads_are_appended_in_order_and_sixth_image_is_rejected_without_writing_files(): void
    {
        $product = Product::factory()->create();
        $admin = User::factory()->admin()->create();
        $this->existingImage($product, 3);

        foreach (range(1, 4) as $number) {
            $this->upload($product, UploadedFile::fake()->image("image-$number.png"), $admin)->assertCreated();
        }
        $this->upload($product, UploadedFile::fake()->image('sixth.png'), $admin)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => '商品画像は5枚以内で登録してください。']);

        $this->assertSame([3, 4, 5, 6, 7], $product->images()->pluck('sort_order')->all());
        $this->assertCount(6, Storage::disk('product_images')->allFiles());
    }

    public function test_image_size_limit_is_five_million_bytes(): void
    {
        $product = Product::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->upload($product, UploadedFile::fake()->image('just-under.jpg')->size(4882), $admin)->assertCreated();
        $this->upload($product, UploadedFile::fake()->image('over.jpg')->size(4883), $admin)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => '画像は1枚5MB以内にしてください。']);

        $this->assertSame(1, $product->images()->count());
    }

    #[TestWith(['bmp'])]
    #[TestWith(['svg'])]
    #[TestWith(['fake'])]
    #[TestWith(['dimensions'])]
    public function test_invalid_upload_is_rejected_without_writing_files(string $case): void
    {
        $file = match ($case) {
            'bmp' => UploadedFile::fake()->image('test.bmp'),
            'svg' => UploadedFile::fake()->createWithContent('test.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'fake' => UploadedFile::fake()->createWithContent('test.jpg', '<?php echo "not an image";'),
            'dimensions' => UploadedFile::fake()->image('test.png', 6001, 1),
        };
        $product = Product::factory()->create();

        $this->upload($product, $file)->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame([], Storage::disk('product_images')->allFiles());
    }

    public function test_pixel_limit_is_checked_before_decoding(): void
    {
        $product = Product::factory()->create();

        $this->upload($product, UploadedFile::fake()->image('large.png', 5000, 4001))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => '画像は2000万画素以内にしてください。']);

        $this->assertSame([], Storage::disk('product_images')->allFiles());
    }

    public function test_storage_failure_returns_error_and_keeps_existing_image(): void
    {
        $product = Product::factory()->create();
        $image = $this->existingImage($product);
        config(['filesystems.product_image_disk' => 'broken_image_disk']);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('admin.products.images.replace', [$product, $image]), ['file' => UploadedFile::fake()->image('new.png')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => '画像を保存できませんでした。ファイルを確認して再度お試しください。']);

        $this->assertSame($image->path, $image->fresh()->path);
        Storage::disk('product_images')->assertExists([$image->path, $image->thumbnail_path]);
    }

    /**
     * @param  array{0: int, 1: int}  $expectedSize
     */
    #[TestWith([6, [40, 80]])]
    #[TestWith([1, [80, 40]])]
    public function test_metadata_is_removed_and_rotated_photos_keep_their_appearance(int $orientation, array $expectedSize): void
    {
        $jpeg = UploadedFile::fake()->image('camera.jpg', 80, 40)->getContent();
        $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvVv', 0x0112, 3, 1, $orientation)."\0\0".pack('V', 0);
        $jpeg = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
        $product = Product::factory()->create();

        $this->upload($product, UploadedFile::fake()->createWithContent('camera.jpg', $jpeg))->assertCreated();

        $image = $product->images()->sole();
        $this->assertSame([...$expectedSize, 'image/jpeg'], [$image->width, $image->height, $image->mime_type]);
        $decoded = new \Imagick;
        $decoded->readImageBlob(Storage::disk('product_images')->get($image->path));
        $this->assertSame('JPEG', $decoded->getImageFormat());
        $this->assertSame($expectedSize, [$decoded->getImageWidth(), $decoded->getImageHeight()]);
        $this->assertSame([], $decoded->getImageProfiles(), '位置情報などの撮影情報は残さない');
        $decoded->clear();
    }

    public function test_replacing_image_saves_new_files_immediately_and_deletes_old_files(): void
    {
        $product = Product::factory()->create();
        $image = $this->existingImage($product, 2);
        $image->update(['alt_text' => '正面']);
        $oldUrl = $image->url;

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('admin.products.images.replace', [$product, $image]), ['file' => UploadedFile::fake()->image('replacement.jpg', 800, 600)])
            ->assertOk()
            ->assertJsonPath('image.id', $image->id)
            ->assertJsonPath('image.alt_text', '正面');

        $fresh = $image->fresh();
        $this->assertNotSame($oldUrl, $fresh->url);
        $this->assertSame([2, 800, 600], [$fresh->sort_order, $fresh->width, $fresh->height]);
        Storage::disk('product_images')->assertMissing([$image->path, $image->thumbnail_path]);
        Storage::disk('product_images')->assertExists([$fresh->path, $fresh->thumbnail_path]);
    }

    public function test_alt_text_is_saved_immediately_and_validated(): void
    {
        $product = Product::factory()->create();
        $image = $this->existingImage($product);
        $this->actingAs(User::factory()->admin()->create());

        $this->patchJson(route('admin.products.images.update', [$product, $image]), ['alt_text' => '商品の背面'])
            ->assertOk()->assertJsonPath('image.alt_text', '商品の背面');
        $this->patchJson(route('admin.products.images.update', [$product, $image]), ['alt_text' => str_repeat('あ', 201)])
            ->assertUnprocessable()->assertJsonValidationErrors(['alt_text' => '画像の説明は200文字以内で入力してください。']);
        $this->patchJson(route('admin.products.images.update', [$product, $image]), ['alt_text' => ''])->assertOk();

        $this->assertNull($image->fresh()->alt_text);
    }

    public function test_images_are_reordered_immediately_and_stale_order_is_rejected(): void
    {
        $product = Product::factory()->create();
        $first = $this->existingImage($product, 0);
        $second = $this->existingImage($product, 1);
        $third = $this->existingImage($product, 2);
        $this->actingAs(User::factory()->admin()->create());

        $this->putJson(route('admin.products.images.reorder', $product), ['ids' => [$third->id, $first->id, $second->id]])->assertNoContent();
        $this->assertSame([$third->id, $first->id, $second->id], $product->images()->pluck('id')->all());

        $this->putJson(route('admin.products.images.reorder', $product), ['ids' => [$first->id, $second->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['ids' => '画像が別の画面で変更されています。ページを再読み込みしてください。']);
        $this->putJson(route('admin.products.images.reorder', $product), ['ids' => [$first->id, $first->id, $second->id]])
            ->assertUnprocessable();
        $this->assertSame([$third->id, $first->id, $second->id], $product->images()->pluck('id')->all());
    }

    public function test_image_is_deleted_immediately_with_its_files(): void
    {
        $product = Product::factory()->create();
        $image = $this->existingImage($product);
        $kept = $this->existingImage($product, 1);

        $this->actingAs(User::factory()->admin()->create())
            ->deleteJson(route('admin.products.images.destroy', [$product, $image]))
            ->assertNoContent();

        $this->assertModelMissing($image);
        $this->assertModelExists($kept);
        Storage::disk('product_images')->assertMissing([$image->path, $image->thumbnail_path]);
        Storage::disk('product_images')->assertExists([$kept->path, $kept->thumbnail_path]);
    }

    public function test_another_products_image_cannot_be_changed_through_this_product(): void
    {
        $product = Product::factory()->create();
        $foreign = $this->existingImage(Product::factory()->create());
        $this->actingAs(User::factory()->admin()->create());

        $this->postJson(route('admin.products.images.replace', [$product, $foreign]), ['file' => UploadedFile::fake()->image('new.png')])->assertNotFound();
        $this->patchJson(route('admin.products.images.update', [$product, $foreign]), ['alt_text' => '変更'])->assertNotFound();
        $this->deleteJson(route('admin.products.images.destroy', [$product, $foreign]))->assertNotFound();
        $this->putJson(route('admin.products.images.reorder', $product), ['ids' => [$foreign->id]])->assertUnprocessable();

        $this->assertSame($foreign->path, $foreign->fresh()->path);
        $this->assertNull($foreign->fresh()->alt_text);
        Storage::disk('product_images')->assertExists([$foreign->path, $foreign->thumbnail_path]);
    }

    public function test_saving_the_product_form_does_not_change_images(): void
    {
        $product = Product::factory()->create();
        $admin = User::factory()->admin()->create();
        $image = $this->existingImage($product);
        $this->upload($product, UploadedFile::fake()->image('added-while-editing.png'), $admin)->assertCreated();

        $this->actingAs($admin)->put(route('admin.products.update', $product), $this->productInput($product, [
            'name' => '名前を変更',
            'images' => [],
        ]))->assertSessionHasNoErrors();

        $this->assertSame('名前を変更', $product->fresh()->name);
        $this->assertSame(2, $product->images()->count());
        Storage::disk('product_images')->assertExists([$image->path, $image->thumbnail_path]);
    }

    public function test_image_urls_are_offered_only_on_the_edit_page(): void
    {
        $product = Product::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('admin.products.create'))->assertInertia(fn (Assert $page) => $page->missing('imagesUrl'));
        $this->get(route('admin.products.edit', $product))->assertInertia(fn (Assert $page) => $page
            ->where('imagesUrl', route('admin.products.images.store', $product, false)));
    }

    public function test_guests_customers_and_unverified_admins_cannot_change_images(): void
    {
        $product = Product::factory()->create();
        $image = $this->existingImage($product);
        $file = UploadedFile::fake()->image('photo.jpg');

        $this->postJson(route('admin.products.images.store', $product), ['file' => $file])->assertUnauthorized();
        $this->deleteJson(route('admin.products.images.destroy', [$product, $image]))->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $this->postJson(route('admin.products.images.store', $product), ['file' => $file])->assertForbidden();
        $this->deleteJson(route('admin.products.images.destroy', [$product, $image]))->assertForbidden();
        $this->actingAs(User::factory()->admin()->unverified()->create());
        $this->postJson(route('admin.products.images.store', $product), ['file' => $file])->assertForbidden();
        $this->deleteJson(route('admin.products.images.destroy', [$product, $image]))->assertForbidden();

        $this->assertSame(1, $product->images()->count());
        Storage::disk('product_images')->assertExists([$image->path, $image->thumbnail_path]);
    }

    public function test_private_images_require_verified_admin_and_publication_changes_apply_immediately(): void
    {
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
        $product = Product::factory()->create(['registration_status' => 'confirmed', 'publication_status' => 'public']);
        $image = $this->existingImage($product);

        $this->get($image->url)->assertOk();
        Storage::disk('product_images')->delete($image->path);
        $this->get($image->url)->assertNotFound();
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
