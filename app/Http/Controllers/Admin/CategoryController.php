<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Categories/Index', ['categories' => Category::treeOptions(Category::withCount('products')->get())]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Categories/Form', ['category' => null, 'parents' => Category::treeOptions()]);
    }

    public function edit(Category $category): Response
    {
        $nodes = Category::all();
        $excluded = Category::subtreeIds($category->id, $nodes);

        return Inertia::render('Admin/Categories/Form', [
            'category' => $category,
            'parents' => array_values(array_filter(Category::treeOptions($nodes), fn (array $node): bool => ! in_array($node['id'], $excluded, true))),
        ]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        $this->saveCategory($request);

        return to_route('admin.categories.index')->with('status', '商品分類を作成しました。');
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $this->saveCategory($request, $category);

        return to_route('admin.categories.index')->with('status', '商品分類を保存しました。販売商品の関連は保持されています。');
    }

    private function saveCategory(CategoryRequest $request, ?Category $category = null): void
    {
        DB::transaction(function () use ($request, $category): void {
            /** Serialize hierarchy edits so simultaneous moves cannot create a cycle. */
            $nodes = Category::orderBy('id')->lockForUpdate()->get();
            $current = $category ? $nodes->find($category->id) : new Category;
            abort_unless($current, 404);
            if ($category && $current->lock_version !== (int) $request->validated('lock_version')) {
                throw ValidationException::withMessages(['lock_version' => '別の画面で更新されています。再読み込みして内容を確認してください。']);
            }
            $parentId = $request->validated('parent_id');
            if ($category && $parentId && in_array((int) $parentId, Category::subtreeIds($current->id, $nodes), true)) {
                throw ValidationException::withMessages(['parent_id' => '自分自身や配下の分類を親にすることはできません。']);
            }
            $current->fill($request->safe()->except('lock_version'));
            if ($category) {
                $current->lock_version++;
            }
            $current->save();
        }, 3);
    }
}
