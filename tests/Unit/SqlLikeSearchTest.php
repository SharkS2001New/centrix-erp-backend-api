<?php

namespace Tests\Unit;

use App\Support\SqlLikeSearch;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SqlLikeSearchTest extends TestCase
{
    public function test_escape_like_wildcards(): void
    {
        $this->assertSame('100\\%\_safe', SqlLikeSearch::escape('100%_safe'));
    }

    public function test_apply_product_search_uses_substring_match_for_code_and_name(): void
    {
        $query = DB::table('products');
        SqlLikeSearch::applyProductSearch($query, 'MID-001');

        $bindings = $query->getBindings();

        $this->assertContains('%MID-001%', $bindings);
        $this->assertSame(2, count($bindings));
    }

    public function test_apply_sales_order_search_uses_substring_on_order_and_customer_num(): void
    {
        $query = DB::table('sales');
        SqlLikeSearch::applySalesOrderSearch($query, '42');

        $bindings = $query->getBindings();
        $this->assertContains('%42%', $bindings);
        $this->assertGreaterThanOrEqual(2, count($bindings));
    }

    public function test_apply_customer_search_uses_substring_on_all_fields(): void
    {
        $query = DB::table('customers');
        SqlLikeSearch::applyCustomerSearch($query, '2547');

        $bindings = $query->getBindings();
        $this->assertTrue(
            collect($bindings)->filter(fn ($binding) => $binding === '%2547%')->isNotEmpty(),
        );
    }

    public function test_empty_search_term_is_no_op(): void
    {
        $query = DB::table('products');
        SqlLikeSearch::applyProductSearch($query, '   ');

        $this->assertSame([], $query->getBindings());
        $this->assertInstanceOf(Builder::class, $query);
    }
}
