<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\HomepageContent;
use App\Models\Product;
use App\Support\StorefrontCategories;
use App\Support\UbiquitiSeoCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class UbiquitiCategoryTreeSeeder extends Seeder
{
    /**
     * Apply the Ubiquiti category tree, product assignments and navigation.
     * The operation is idempotent: categories are matched by slug and product
     * assignments are merged with existing valid assignments.
     */
    public function run(): void
    {
        $ids = $this->upsertCategories();
        $updatedProducts = $this->assignProducts();

        $this->syncNavigation();

        $publishedIds = StorefrontCategories::publishedIds();
        $rows = [];

        foreach ($ids as $slug => $id) {
            $category = Category::query()->find($id);

            if (! $category) {
                continue;
            }

            $rows[] = [
                $slug,
                $category->name,
                $category->h1,
                in_array($id, $publishedIds, true) ? 'published' : 'hidden (empty)',
            ];
        }

        if ($this->command && $rows !== []) {
            $this->command->table(['Slug', 'Name', 'H1', 'Status'], $rows);
            $this->command->info('Products checked: '.$updatedProducts.'.');
        }
    }

    /**
     * @return array<string, int>
     */
    private function upsertCategories(): array
    {
        $definitions = [];

        foreach (UbiquitiSeoCatalog::mainCategories() as $slug => $data) {
            $definitions[$slug] = [
                'name' => $data['name'],
                'h1' => $data['h1'],
                'seo_title' => $data['seo_title'],
                'focus_keyword' => $data['focus_keyword'],
                'meta_description' => $data['meta_description'],
                'intro' => $data['intro'],
                'description' => $data['description'],
                'seo_content' => $data['seo_content'],
                'faq_items' => $data['faq_items'],
                'parent' => null,
            ];
        }

        $subcategories = [
            'wifi-7-access-points' => ['Wi-Fi 7 Access Points', 'ubiquiti-access-points'],
            'wifi-6-access-points' => ['Wi-Fi 6 Access Points', 'ubiquiti-access-points'],
            'wifi-6e-access-points' => ['Wi-Fi 6E Access Points', 'ubiquiti-access-points'],
            'indoor-access-points' => ['Indoor Access Points', 'ubiquiti-access-points'],
            'in-wall-access-points' => ['In-Wall Access Points', 'ubiquiti-access-points'],
            'wall-access-points' => ['Wall Access Points', 'ubiquiti-access-points'],
            'outdoor-access-points' => ['Outdoor Access Points', 'ubiquiti-access-points'],
            'long-range-access-points' => ['Long Range Access Points', 'ubiquiti-access-points'],
            'enterprise-access-points' => ['Enterprise Access Points', 'ubiquiti-access-points'],
            'poe-switches' => ['PoE Switches', 'ubiquiti-switches'],
            'non-poe-switches' => ['Non-PoE Switches', 'ubiquiti-switches'],
            'desktop-switches' => ['Desktop Switches', 'ubiquiti-switches'],
            'rackmount-switches' => ['Rackmount Switches', 'ubiquiti-switches'],
            'aggregation-switches' => ['Aggregation Switches', 'ubiquiti-switches'],
            'ubiquiti-cloud-gateways' => ['Cloud Gateways', 'ubiquiti-routers'],
            'dream-machines' => ['Dream Machines', 'ubiquiti-routers'],
            'cloudkeys' => ['CloudKeys', 'network-controllers'],
            'unifi-network-consoles' => ['UniFi Network Consoles', 'network-controllers'],
            'ubiquiti-airmax' => ['airMAX', 'wireless-bridges-wisp'],
            'ubiquiti-airfiber' => ['airFiber', 'wireless-bridges-wisp'],
            'ubiquiti-point-to-point' => ['Point-to-Point', 'wireless-bridges-wisp'],
            'point-to-multipoint' => ['Point-to-Multipoint Wireless', 'wireless-bridges-wisp'],
            'wave' => ['Wave', 'wireless-bridges-wisp'],
            'unifi-building-bridge' => ['UniFi Building Bridge', 'wireless-bridges-wisp'],
            'ubiquiti-antennas' => ['Antennas', 'wireless-bridges-wisp'],
            'sfp-modules' => ['SFP Modules', 'fiber-sfp-modules'],
            'sfp-plus-modules' => ['SFP+ Modules', 'fiber-sfp-modules'],
            'qsfp-modules' => ['QSFP Modules', 'fiber-sfp-modules'],
            'dac-cables' => ['DAC Cables', 'fiber-sfp-modules'],
            'fiber-accessories' => ['Fiber Accessories', 'fiber-sfp-modules'],
            'poe-injectors' => ['PoE Injectors', 'ubiquiti-network-accessories'],
            'power-supplies' => ['Power Supplies', 'ubiquiti-network-accessories'],
            'network-mounts' => ['Network Mounts', 'ubiquiti-network-accessories'],
            'ethernet-surge-protectors' => ['Ethernet Surge Protectors', 'ubiquiti-network-accessories'],
        ];

        foreach ($subcategories as $slug => [$name, $parent]) {
            $definitions[$slug] = [
                'name' => $name,
                'parent' => $parent,
            ];
        }

        return $this->persistCategories($definitions);
    }

    /**
     * @param  array<string, array<string, mixed>>  $definitions
     * @return array<string, int>
     */
    private function persistCategories(array $definitions): array
    {
        $ids = [];

        foreach ($definitions as $slug => $data) {
            $parentSlug = $data['parent'];
            unset($data['parent']);

            $payload = array_merge($data, [
                'slug' => $slug,
                'parent_id' => $parentSlug ? ($ids[$parentSlug] ?? null) : null,
            ]);

            $category = Category::query()->updateOrCreate(['slug' => $slug], $payload);
            $ids[$slug] = $category->id;
        }

        return $ids;
    }

    private function assignProducts(): int
    {
        if (! Product::categoryAssignmentsReady()) {
            return 0;
        }

        $categories = Category::query()->get()->keyBy('slug');
        $products = Product::query()->with(['category', 'categories'])->get();
        $apSubcategories = [
            'wifi-7-access-points',
            'wifi-6-access-points',
            'wifi-6e-access-points',
            'indoor-access-points',
            'in-wall-access-points',
            'wall-access-points',
            'outdoor-access-points',
            'long-range-access-points',
            'enterprise-access-points',
        ];
        $switchSubcategories = ['poe-switches', 'non-poe-switches', 'desktop-switches', 'rackmount-switches'];

        foreach ($products as $product) {
            $assigned = $product->categories->pluck('slug')->all();
            $primarySlug = $product->category?->slug;
            $name = (string) $product->name;
            $sku = (string) $product->sku;

            if ($primarySlug === 'ubiquiti-access-points') {
                $assigned = array_values(array_diff($assigned, $apSubcategories));

                if (Str::contains($product->key_specifications, ['WiFi 6E', 'Wi-Fi 6E'])) {
                    $assigned[] = 'wifi-6e-access-points';
                } elseif (Str::contains($product->key_specifications, ['WiFi 7', 'Wi-Fi 7'])) {
                    $assigned[] = 'wifi-7-access-points';
                } elseif (Str::contains($product->key_specifications, ['WiFi 6', 'Wi-Fi 6'])) {
                    $assigned[] = 'wifi-6-access-points';
                }

                $isOutdoor = Str::contains($name, ['Outdoor', 'Mesh']);
                $assigned[] = $isOutdoor ? 'outdoor-access-points' : 'indoor-access-points';

                if (Str::contains($name, ['In-Wall'])) {
                    $assigned[] = 'in-wall-access-points';
                }

                if (Str::contains($name, ['Wall'])) {
                    $assigned[] = 'wall-access-points';
                }

                if (Str::contains($name, ['Long-Range', 'Long Range'])) {
                    $assigned[] = 'long-range-access-points';
                }

                if (Str::contains($name, ['Enterprise'])) {
                    $assigned[] = 'enterprise-access-points';
                }
            }

            if ($primarySlug === 'ubiquiti-switches') {
                $assigned = array_values(array_diff($assigned, $switchSubcategories));
                $hasPoe = Str::contains($name, ['PoE']) || Str::contains($sku, ['PoE', 'POE']);
                $assigned[] = $hasPoe ? 'poe-switches' : 'non-poe-switches';

                preg_match('/Form Factor:\s*([^\r\n]+)/i', (string) $product->technical_specifications, $formFactor);
                $formFactor = $formFactor[1] ?? '';

                if (stripos($formFactor, 'desktop') !== false) {
                    $assigned[] = 'desktop-switches';
                }

                if (stripos($formFactor, 'rack mount') !== false) {
                    $assigned[] = 'rackmount-switches';
                }
            }

            if ($primarySlug === 'ubiquiti-cloud-gateways') {
                $assigned[] = 'ubiquiti-routers';
                $assigned[] = 'ubiquiti-cloud-gateways';

                if (Str::contains($name, ['Dream Machine'])) {
                    $assigned[] = 'dream-machines';
                }
            }

            if (in_array($primarySlug, ['ubiquiti-airmax', 'ubiquiti-airfiber', 'ubiquiti-antennas'], true)) {
                $assigned[] = 'wireless-bridges-wisp';
            }

            if ($primarySlug === 'ubiquiti-airmax') {
                $assigned[] = 'ubiquiti-airmax';

                if (Str::contains($name, ['NanoStation', 'LiteAP', 'Rocket'])) {
                    $assigned[] = 'point-to-multipoint';
                }
            }

            if ($primarySlug === 'ubiquiti-airfiber') {
                $assigned[] = 'ubiquiti-airfiber';
                $assigned[] = 'ubiquiti-point-to-point';
            }

            if (Str::contains($name, ['Wave'])) {
                $assigned[] = 'wireless-bridges-wisp';
                $assigned[] = 'wave';
            }

            if (Str::contains($name, ['Building Bridge'])) {
                $assigned[] = 'wireless-bridges-wisp';
                $assigned[] = 'unifi-building-bridge';
            }

            if (Str::contains($name, ['PoE Adapter', 'PoE Injector'])) {
                $assigned[] = 'ubiquiti-network-accessories';
                $assigned[] = 'poe-injectors';
            }

            if (Str::contains($name, ['CloudKey', 'Cloud Key'])) {
                $assigned[] = 'network-controllers';
                $assigned[] = 'cloudkeys';
            }

            $assigned = collect($assigned)
                ->filter(fn ($slug): bool => is_string($slug) && $categories->has($slug))
                ->unique()
                ->map(fn (string $slug): int => $categories->get($slug)->id)
                ->values()
                ->all();

            $product->categories()->sync($assigned);
        }

        return $products->count();
    }

    private function syncNavigation(): void
    {
        $publishedIds = StorefrontCategories::publishedIds();
        $menu = [];

        foreach (UbiquitiSeoCatalog::mainCategories() as $slug => $data) {
            $category = Category::query()->where('slug', $slug)->first();

            if ($category && in_array($category->id, $publishedIds, true)) {
                $menu[] = [
                    'label' => $data['name'],
                    'url' => '/category/'.$slug,
                ];
            }
        }

        if ($menu === []) {
            return;
        }

        $homepage = HomepageContent::query()
            ->where('site_key', HomepageContent::DEFAULT_SITE_KEY)
            ->first();

        if (! $homepage) {
            HomepageContent::create([
                'site_key' => HomepageContent::DEFAULT_SITE_KEY,
                'hero_title' => 'Ubiquiti UniFi Kenya - Access Points, Switches & Gateways',
                'hero_description' => 'Compare Ubiquiti UniFi access points, switches, gateways, wireless equipment and accessories with current catalogue prices and stock information for Kenya.',
                'nav_menu_items' => $menu,
            ]);

            return;
        }

        $existingItems = $homepage->navMenuItems();
        $changed = false;

        foreach ($menu as $item) {
            if (count($existingItems) >= 8) {
                break;
            }

            $alreadyPresent = collect($existingItems)->contains(
                fn (array $existing): bool => rtrim((string) (parse_url($existing['url'], PHP_URL_PATH) ?: $existing['url']), '/') === $item['url']
            );

            if (! $alreadyPresent) {
                $existingItems[] = $item;
                $changed = true;
            }
        }

        if ($changed) {
            $homepage->update(['nav_menu_items' => $existingItems]);
        }
    }
}
