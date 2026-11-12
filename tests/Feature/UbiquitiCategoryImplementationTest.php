<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\CanonicalUrl;
use Database\Seeders\UbiquitiCategoryTreeSeeder;
use Database\Seeders\UbiquitiNetworkingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UbiquitiCategoryImplementationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UbiquitiNetworkingSeeder::class);
        $this->seed(UbiquitiCategoryTreeSeeder::class);
    }

    public function test_main_category_pages_use_the_required_h1_canonical_and_product_counts(): void
    {
        $expectations = [
            'ubiquiti-access-points' => ['Ubiquiti Wi-Fi Access Points in Kenya', 18],
            'ubiquiti-switches' => ['Ubiquiti UniFi Network Switches in Kenya', 23],
            'ubiquiti-routers' => ['Ubiquiti UniFi Routers & Gateways in Kenya', 5],
            'wireless-bridges-wisp' => ['Ubiquiti Wireless Bridges & WISP Equipment in Kenya', 19],
            'ubiquiti-network-accessories' => ['Ubiquiti PoE Injectors & Network Accessories in Kenya', 2],
        ];

        foreach ($expectations as $slug => [$h1, $count]) {
            $response = $this->get('/category/'.$slug)->assertOk();

            $response->assertSee('<h1>'.e($h1).'</h1>', false);
            $response->assertSee('<link rel="canonical" href="'.CanonicalUrl::PRODUCTION_URL.'/category/'.$slug.'">', false);
            $this->assertSame($count, substr_count($response->getContent(), 'class="product-card"'), $slug);
        }
    }

    public function test_empty_main_categories_are_noindexed_and_not_in_menu_or_sitemap(): void
    {
        foreach (['network-controllers' => 'Ubiquiti UniFi Network Controllers in Kenya', 'fiber-sfp-modules' => 'Ubiquiti Fiber Equipment & SFP Modules in Kenya'] as $slug => $h1) {
            $response = $this->get('/category/'.$slug)->assertOk();

            $response->assertSee('<h1>'.e($h1).'</h1>', false);
            $response->assertSee('<meta name="robots" content="noindex,follow">', false);
            $response->assertDontSee('class="product-card"', false);
        }

        $this->get('/')
            ->assertOk()
            ->assertSee('Wi-Fi Access Points')
            ->assertSee('Network Switches')
            ->assertSee('Routers &amp; Gateways', false)
            ->assertSee('Wireless Bridges &amp; WISP', false)
            ->assertSee('PoE &amp; Accessories', false)
            ->assertDontSee('Network Controllers')
            ->assertDontSee('Fiber &amp; SFP Modules', false);

        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString(CanonicalUrl::PRODUCTION_URL.'/category/ubiquiti-access-points', $sitemap);
        $this->assertStringNotContainsString('/category/network-controllers', $sitemap);
        $this->assertStringNotContainsString('/category/fiber-sfp-modules', $sitemap);
        $this->assertStringNotContainsString('ubiquityunifikenya', $sitemap);
    }

    public function test_category_metadata_breadcrumbs_and_product_urls_use_the_public_domain(): void
    {
        $this->get('/category/wireless-bridges-wisp')
            ->assertOk()
            ->assertSee('<title>Ubiquiti Wireless Bridges &amp; WISP in Kenya</title>', false)
            ->assertSee('<meta name="description" content="Compare Ubiquiti airMAX, airFiber, Wave and UniFi Building Bridge equipment in Kenya for point-to-point links and wireless ISP networks.">', false)
            ->assertSee('"@type":"BreadcrumbList"', false)
            ->assertSee(CanonicalUrl::PRODUCTION_URL.'/category/wireless-bridges-wisp', false);

        $product = Product::query()->where('sku', 'U7-Lite')->firstOrFail();

        $this->get('/product/'.$product->slug)
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.CanonicalUrl::PRODUCTION_URL.'/product/'.$product->slug.'">', false)
            ->assertSee('KSh 17,500.00');
    }

    public function test_retired_category_urls_redirect_to_the_new_taxonomy(): void
    {
        $this->get('/ubiquiti-fiber')->assertRedirect('/category/fiber-sfp-modules');
        $this->get('/category/ubiquiti-fiber')->assertRedirect('/category/fiber-sfp-modules');
        $this->get('/ubiquiti-poe-injectors')->assertRedirect('/category/poe-injectors');
        $this->get('/ubiquiti-cloud-key')->assertRedirect('/category/network-controllers');
    }

    public function test_products_are_assigned_to_the_expected_categories(): void
    {
        $this->assertProductCategories('U6-Enterprise', ['ubiquiti-access-points', 'wifi-6e-access-points', 'indoor-access-points', 'enterprise-access-points']);
        $this->assertProductCategories('U7-IW', ['wifi-7-access-points', 'in-wall-access-points', 'wall-access-points', 'indoor-access-points']);
        $this->assertProductCategories('USW-Lite-16-PoE (45W)', ['ubiquiti-switches', 'poe-switches', '16-port-switches', 'desktop-switches']);
        $this->assertProductCategories('USW-24', ['ubiquiti-switches', 'non-poe-switches', '24-port-switches', 'rackmount-switches']);
        $this->assertProductCategories('UDM-Pro', ['ubiquiti-routers', 'ubiquiti-cloud-gateways', 'dream-machines']);
        $this->assertProductCategories('AF-5XHD', ['wireless-bridges-wisp', 'ubiquiti-airfiber', 'ubiquiti-point-to-point']);
        $this->assertProductCategories('NSM5', ['wireless-bridges-wisp', 'ubiquiti-airmax', 'point-to-multipoint', 'ubiquiti-point-to-point']);
        $this->assertProductCategories('POE-24-24V-0.5A', ['ubiquiti-network-accessories', 'poe-injectors']);
    }

    public function test_access_point_wifi_and_poe_filters_return_matching_models(): void
    {
        $response = $this->get('/category/ubiquiti-access-points?wifi=wifi-6e')
            ->assertOk()
            ->assertSee('Ubiquiti U6 Enterprise')
            ->assertDontSee('Ubiquiti U7 Pro');

        $response->assertSee('<meta name="robots" content="noindex,follow">', false);

        $this->get('/category/ubiquiti-access-points?poe=poe-plus-plus')
            ->assertOk()
            ->assertSee('Ubiquiti U7 Pro XGS')
            ->assertSee('Ubiquiti U6 Enterprise In-Wall')
            ->assertDontSee('Ubiquiti U7 Lite');
    }

    public function test_wisp_link_type_and_product_line_filters_return_matching_models(): void
    {
        $this->get('/category/wireless-bridges-wisp?link=point-to-multipoint')
            ->assertOk()
            ->assertSee('Ubiquiti airMAX NanoStation')
            ->assertDontSee('Ubiquiti airMAX LiteBeam');

        $this->get('/category/wireless-bridges-wisp?line=airfiber')
            ->assertOk()
            ->assertSee('Ubiquiti airFiber 5XHD')
            ->assertDontSee('Ubiquiti airMAX NanoStation');
    }

    /**
     * @param  array<int, string>  $expected
     */
    private function assertProductCategories(string $sku, array $expected): void
    {
        $product = Product::query()->where('sku', $sku)->firstOrFail();
        $actual = $product->categories()->pluck('slug')->all();

        foreach ($expected as $slug) {
            $this->assertContains($slug, $actual, $sku.' should be assigned to '.$slug);
        }
    }
}
