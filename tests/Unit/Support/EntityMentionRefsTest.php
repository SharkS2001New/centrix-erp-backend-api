<?php

namespace Tests\Unit\Support;

use App\Support\EntityMentionRefs;
use Tests\TestCase;

class EntityMentionRefsTest extends TestCase
{
    public function test_normalize_filters_and_caps_refs(): void
    {
        $refs = EntityMentionRefs::normalize([
            ['type' => 'product', 'code' => 'SUG50', 'label' => 'Sugar'],
            ['type' => 'customer', 'code' => '42', 'label' => 'Acme'],
            ['type' => 'nope', 'label' => 'x'],
            ['type' => 'supplier', 'id' => '7', 'label' => 'Vendor'],
        ]);

        $this->assertCount(3, $refs);
        $this->assertSame(['SUG50'], EntityMentionRefs::productCodes($refs));
        $this->assertSame(['42'], EntityMentionRefs::customerNums($refs));
        $this->assertSame([7], EntityMentionRefs::supplierIds($refs));
        $this->assertTrue(EntityMentionRefs::hasType($refs, 'product'));
        $this->assertFalse(EntityMentionRefs::hasType($refs, 'branch'));
    }
}
