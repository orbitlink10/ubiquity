<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;

class StorefrontCategories
{
    /**
     * Category slugs in the order they should appear in public navigation.
     */
    private const MENU_ORDER = [
        'ubiquiti-access-points' => 0,
        'ubiquiti-switches' => 1,
        'ubiquiti-routers' => 2,
        'network-controllers' => 3,
        'wireless-bridges-wisp' => 4,
        'fiber-sfp-modules' => 5,
        'ubiquiti-network-accessories' => 6,
        'wifi-7-access-points' => 10,
        'wifi-6-access-points' => 11,
        'wifi-6e-access-points' => 12,
        'indoor-access-points' => 13,
        'in-wall-access-points' => 14,
        'wall-access-points' => 15,
        'outdoor-access-points' => 16,
        'long-range-access-points' => 17,
        'enterprise-access-points' => 18,
        'poe-switches' => 20,
        'non-poe-switches' => 21,
        'desktop-switches' => 22,
        'rackmount-switches' => 23,
        'aggregation-switches' => 24,
        '8-port-switches' => 25,
        '16-port-switches' => 26,
        '24-port-switches' => 27,
        '48-port-switches' => 28,
        'multi-gigabit-switches' => 29,
        'enterprise-switches' => 30,
        '10g-switches' => 31,
        'ubiquiti-cloud-gateways' => 35,
        'dream-machines' => 36,
        'cloudkeys' => 40,
        'unifi-network-consoles' => 41,
        'ubiquiti-airmax' => 50,
        'ubiquiti-airfiber' => 51,
        'ubiquiti-point-to-point' => 52,
        'point-to-multipoint' => 53,
        'wave' => 54,
        'unifi-building-bridge' => 55,
        'ubiquiti-antennas' => 56,
        'sfp-modules' => 60,
        'sfp-plus-modules' => 61,
        'qsfp-modules' => 62,
        'dac-cables' => 63,
        'fiber-accessories' => 64,
        'poe-injectors' => 70,
        'power-supplies' => 71,
        'network-mounts' => 72,
        'ethernet-surge-protectors' => 73,
    ];

    /**
     * @return Collection<int, Category>
     */
    public static function menu(): Collection
    {
        $publishedIds = self::publishedIds();

        $categories = Category::query()
            ->whereNull('parent_id')
            ->whereIn('id', $publishedIds)
            ->with(['children' => fn ($query) => $query->whereIn('id', $publishedIds)])
            ->get();

        return self::sorted($categories)
            ->each(fn (Category $category) => $category->setRelation('children', self::sorted($category->children)))
            ->values();
    }

    /**
     * @return Collection<int, Category>
     */
    public static function sitemap(): Collection
    {
        return Category::query()
            ->whereIn('id', self::publishedIds())
            ->whereNotIn('slug', array_keys(UbiquitiSeoCatalog::legacyCategoryRedirects()))
            ->when(SeoMetadata::columnReady('categories', 'robots'), fn ($query) => $query->where(fn ($robotsQuery) => $robotsQuery->whereNull('robots')->orWhere('robots', 'not like', 'noindex%')))
            ->orderBy('updated_at', 'desc')
            ->get();
    }

    /**
     * Categories with at least one active product, plus their ancestors.
     *
     * @return array<int, int>
     */
    public static function publishedIds(): array
    {
        $directIds = Product::query()
            ->active()
            ->with('categories:id')
            ->get(['id', 'category_id'])
            ->flatMap(function (Product $product): array {
                return array_filter(array_merge(
                    [$product->category_id],
                    $product->categories->pluck('id')->all()
                ));
            })
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($directIds === []) {
            return [];
        }

        $categories = Category::query()->get(['id', 'parent_id'])->keyBy('id');
        $published = [];

        foreach ($directIds as $id) {
            $category = $categories->get($id);

            while ($category) {
                $published[$category->id] = true;
                $category = $category->parent_id ? $categories->get($category->parent_id) : null;
            }
        }

        return array_keys($published);
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return Collection<int, Category>
     */
    private static function sorted(Collection $categories): Collection
    {
        return $categories
            ->sortBy(function (Category $category): int {
                return self::MENU_ORDER[$category->slug] ?? 999;
            })
            ->values();
    }
}
