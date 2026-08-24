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

    public function test_normalize_accepts_user_and_employee_refs(): void
    {
        $refs = EntityMentionRefs::normalize([
            ['type' => 'employee', 'id' => '3', 'code' => 'E001', 'label' => 'Jane Doe'],
            ['type' => 'user', 'id' => '9', 'label' => 'Diana'],
        ]);

        $this->assertCount(2, $refs);
        $this->assertTrue(EntityMentionRefs::hasType($refs, 'employee'));
        $this->assertTrue(EntityMentionRefs::hasType($refs, 'user'));
        $this->assertSame('3', $refs[0]['id']);
        $this->assertSame('9', $refs[1]['id']);
    }
}
