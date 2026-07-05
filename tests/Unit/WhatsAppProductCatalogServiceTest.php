<?php

namespace Tests\Unit;

use App\Services\WhatsApp\WhatsAppProductCatalogService;
use Tests\TestCase;

class WhatsAppProductCatalogServiceTest extends TestCase
{
    public function test_parse_product_query_supports_qty_prefix_and_suffix(): void
    {
        $service = app(WhatsAppProductCatalogService::class);

        $this->assertSame(['qty' => 2.0, 'term' => 'halisi'], $service->parseProductQuery('2 halisi'));
        $this->assertSame(['qty' => 3.0, 'term' => 'halisi 20l'], $service->parseProductQuery('3x halisi 20l'));
        $this->assertSame(['qty' => 5.0, 'term' => 'cooking oil'], $service->parseProductQuery('cooking oil 5'));
        $this->assertSame(['qty' => null, 'term' => 'halisi jerican'], $service->parseProductQuery('halisi jerican'));
    }
}
