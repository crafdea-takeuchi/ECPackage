<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'parent_id', 'sort_order', 'status'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['parent_id' => 'integer', 'sort_order' => 'integer', 'lock_version' => 'integer'];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    /** @return list<array<string, mixed>> */
    public static function treeOptions(?Collection $nodes = null): array
    {
        $nodes ??= static::query()->get();
        $children = $nodes->sortBy([['sort_order', 'asc'], ['id', 'asc']])->groupBy(fn (Category $node) => $node->parent_id ?? 0);
        $result = [];
        $visit = function (int $parent, array $ancestors, bool $active) use (&$visit, &$result, $children): void {
            foreach ($children->get($parent, collect()) as $node) {
                $path = [...$ancestors, $node->name];
                $effectiveActive = $active && $node->status === 'active';
                $result[] = [
                    'id' => $node->id, 'name' => $node->name, 'parent_id' => $node->parent_id,
                    'path' => implode(' → ', $path), 'depth' => count($ancestors),
                    'sort_order' => $node->sort_order, 'status' => $node->status,
                    'effective_active' => $effectiveActive,
                    'products_count' => $node->products_count ?? 0,
                ];
                $visit($node->id, $path, $effectiveActive);
            }
        };
        $visit(0, [], true);

        return $result;
    }

    /** @return list<int> */
    public static function subtreeIds(int $id, ?Collection $nodes = null): array
    {
        $nodes ??= static::query()->get(['id', 'parent_id']);
        $children = $nodes->groupBy(fn (Category $node) => $node->parent_id ?? 0);
        $ids = [];
        $pending = [$id];
        while ($pending !== []) {
            $current = array_pop($pending);
            if (isset($ids[$current])) {
                continue;
            }
            $ids[$current] = $current;
            foreach ($children->get($current, collect()) as $child) {
                $pending[] = $child->id;
            }
        }

        return array_values($ids);
    }
}
