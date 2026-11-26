<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProductGroupImageTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function input(?ProductGroup $group = null, array $overrides = []): array
    {
        return array_replace(['name' => $group?->name ?? '代表画像付き基本商品', 'status' => 'active',
            'attributes' => [], 'lock_version' => $group?->lock_version ?? 0,
            'images_present' => true, 'images' => []], $overrides);
    }

    private function upload(): ProductGroup
    {
        Storage::fake('product_images');
        $this->actingAs(User::factory()->admin()->create())->post(route('admin.product-groups.store'), $this->input(null, [
            'images' => [['file' => UploadedFile::fake()->image('group.jpg', 2400, 1200)]],
        ]))->assertSessionHasNoErrors();

        return ProductGroup::firstOrFail();
    }

    #[TestWith(['jpg', 'image/jpeg'])]
    #[TestWith(['png', 'image/png'])]
    #[TestWith(['webp', 'image/webp'])]
    #[TestWith(['gif', 'image/gif'])]
    public function test_group_image_is_saved_in_its_original_format_and_size(string $extension, string $mimeType): void
    {
        Storage::fake('product_images');
        $this->actingAs(User::factory()->admin()->create())->post(route('admin.product-groups.store'), $this->input(null, [
            'images' => [['file' => UploadedFile::fake()->image('group.'.$extension, 2400, 1200)]],
        ]))->assertSessionHasNoErrors();
        $group = ProductGroup::firstOrFail();
        $image = $group->images()->firstOrFail();
        $this->assertNull($image->product_id);
        $this->assertSame($group->id, $image->product_group_id);
        $this->assertSame($mimeType, $image->mime_type);
        $this->assertSame([2400, 1200], [$image->width, $image->height]);
        $stored = getimagesizefromstring(Storage::disk('product_images')->get($image->thumbnail_path));
        $this->assertSame([2400, 1200, $mimeType], [$stored[0], $stored[1], $stored['mime']]);
        $this->get(route('admin.product-groups.index'))->assertInertia(fn (Assert $page) => $page->where('groups.0.images.0.id', $image->id));
        $this->get(route('admin.product-groups.show', $group))->assertInertia(fn (Assert $page) => $page->where('productGroup.images.0.id', $image->id));
    }

    public function test_replacement_deletion_and_omitted_fields_preserve_the_correct_files(): void
    {
        $group = $this->upload();
        $old = $group->images()->firstOrFail();
        $this->put(route('admin.product-groups.update', $group), $this->input($group, [
            'images' => [['id' => $old->id, 'file' => UploadedFile::fake()->image('new.png')]],
        ]))->assertSessionHasNoErrors();
        $new = $old->fresh();
        Storage::disk('product_images')->assertMissing([$old->path, $old->thumbnail_path]);
        Storage::disk('product_images')->assertExists([$new->path, $new->thumbnail_path]);
        $this->assertSame(1, $group->images()->count());
        $data = $this->input($group->fresh());
        unset($data['images'], $data['images_present']);
        $this->put(route('admin.product-groups.update', $group), $data)->assertSessionHasNoErrors();
        $this->assertSame($new->path, $new->fresh()->path);
        $this->put(route('admin.product-groups.update', $group), $this->input($group->fresh()))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('product_images', ['id' => $new->id]);
        $this->assertSame([], Storage::disk('product_images')->allFiles());
    }

    public function test_failed_replacement_keeps_existing_image_and_cleans_prepared_files(): void
    {
        $group = $this->upload();
        $image = $group->images()->firstOrFail();
        $group->increment('lock_version');
        $this->put(route('admin.product-groups.update', $group), $this->input($group, [
            'lock_version' => 0,
            'images' => [['id' => $image->id, 'file' => UploadedFile::fake()->image('new.png')]],
        ]))->assertSessionHasErrors('lock_version');
        $this->assertSame($image->path, $image->fresh()->path);
        Storage::disk('product_images')->assertExists([$image->path, $image->thumbnail_path]);
        $this->assertCount(1, Storage::disk('product_images')->allFiles(), '画像1枚につき1ファイル');
    }

    public function test_invalid_images_multiple_images_and_cross_owner_ids_are_rejected(): void
    {
        $group = $this->upload();
        $image = $group->images()->firstOrFail();
        foreach ([UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'), UploadedFile::fake()->image('large.png')->size(5121), UploadedFile::fake()->image('wide.png', 6001, 1)] as $file) {
            $this->put(route('admin.product-groups.update', $group), $this->input($group, ['images' => [['id' => $image->id, 'file' => $file]]]))->assertSessionHasErrors('images.0.file');
        }
        $this->put(route('admin.product-groups.update', $group), $this->input($group, ['images' => [['id' => $image->id], ['file' => UploadedFile::fake()->image('extra.png')]]]))->assertSessionHasErrors('images');
        $other = ProductImage::factory()->create();
        $this->put(route('admin.product-groups.update', $group), $this->input($group, ['images' => [['id' => $other->id]]]))->assertSessionHasErrors('images.0.id');
        $this->assertSame($image->path, $image->fresh()->path);
    }

    public function test_only_verified_admin_can_change_or_view_unpublished_group_images(): void
    {
        $group = $this->upload();
        $url = $group->images()->first()->url;
        $this->get($url)->assertOk();
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        $this->put(route('admin.product-groups.update', $group), $this->input($group))->assertForbidden();
        $this->actingAs(User::factory()->admin()->unverified()->create())->get($url)->assertNotFound();
    }

    public function test_shop_group_and_variant_image_priorities_and_publication_windows(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 18)->setTime(0, 0));
        $group = $this->upload();
        $groupImage = $group->images()->firstOrFail();
        $product = Product::factory()->create(['product_group_id' => $group->id, 'registration_status' => 'confirmed', 'publication_status' => 'public', 'default_sale_price' => 100]);
        $own = ProductImage::factory()->for($product)->create();
        $this->get(route('shop.index'))->assertInertia(fn (Assert $page) => $page->where('products.data.0.images.0.id', $groupImage->id));
        $this->get('/shop/'.$product->id)->assertInertia(fn (Assert $page) => $page->where('product.images.0.id', $own->id));
        $own->delete();
        $this->get('/shop/'.$product->id)->assertInertia(fn (Assert $page) => $page->where('product.images.0.id', $groupImage->id));
        $this->actingAs(User::factory()->create())->get($groupImage->url)->assertOk();
        $product->update(['publication_ends_at' => now()]);
        $this->get($groupImage->url)->assertNotFound();
    }
}
