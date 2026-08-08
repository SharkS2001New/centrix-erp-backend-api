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

        $this->assertContains('MID-001', $bindings);
        $this->assertContains('MID-001%', $bindings);
        $this->assertContains('%MID-001%', $bindings);
        // Compact needle (hyphen stripped) so spaced/punctuated catalog names still hit.
        $this->assertContains('%mid001%', $bindings);
        $this->assertGreaterThanOrEqual(5, count($bindings));
        $this->assertGreaterThanOrEqual(
            3,
            collect($bindings)->filter(fn ($b) => is_string($b) && str_contains($b, 'MID-001'))->count(),
        );
    }

    public function test_apply_product_search_numeric_barcode_skips_name_contains(): void
    {
        $query = DB::table('products');
        SqlLikeSearch::applyProductSearch($query, '6001234567890');

        $bindings = $query->getBindings();

        $this->assertContains('6001234567890', $bindings);
        $this->assertContains('6001234567890%', $bindings);
        $this->assertFalse(
            collect($bindings)->contains(fn ($b) => is_string($b) && str_starts_with($b, '%')),
        );
        $this->assertSame(2, count($bindings));
    }

    public function test_apply_product_search_name_phrase_uses_contains_on_name_and_code(): void
    {
        $query = DB::table('products');
        SqlLikeSearch::applyProductSearch($query, 'cooking oil');

        $bindings = $query->getBindings();
        // Each token matches code / name / shelf (plus compact variants).
        $this->assertContains('%cooking%', $bindings);
        $this->assertContains('%oil%', $bindings);
        $this->assertGreaterThanOrEqual(6, count($bindings));
    }

    public function test_compact_search_needle_strips_spaces_and_punctuation(): void
    {
        $this->assertSame('postman', SqlLikeSearch::compactSearchNeedle('Post Man'));
        $this->assertSame('postman', SqlLikeSearch::compactSearchNeedle('post-man'));
        $this->assertSame('postman', SqlLikeSearch::compactSearchNeedle('  postman  '));
    }

    public function test_apply_product_search_postman_matches_spaced_name_via_compact(): void
    {
        SqlLikeSearch::forceProductNameFulltext(false);
        $query = DB::table('products');
        SqlLikeSearch::applyProductSearch($query, 'postman');

        $sql = $query->toSql();
        $bindings = $query->getBindings();
        $this->assertContains('%postman%', $bindings);
        // Compact column match so "Post Man" is found when the cashier typed "postman".
        $this->assertStringContainsString("REPLACE(REPLACE(REPLACE(LOWER(products.product_name)", $sql);
        $this->assertTrue(
            collect($bindings)->contains(fn ($b) => is_string($b) && str_contains($b, 'postman')),
        );
    }

    public function test_tokenize_splits_multi_word_queries(): void
    {
        $this->assertSame(['sugar', '50'], SqlLikeSearch::tokenize(' sugar  50 '));
    }

    public function test_apply_sales_order_search_uses_substring_on_order_and_customer_num(): void
    {
        $query = DB::table('sales');
        SqlLikeSearch::applySalesOrderSearch($query, 'Acme');

        $bindings = $query->getBindings();
        $this->assertContains('%Acme%', $bindings);
        $this->assertGreaterThanOrEqual(1, count($bindings));
    }

    public function test_apply_sales_order_search_numeric_uses_exact_order_num(): void
    {
        $query = DB::table('sales');
        SqlLikeSearch::applySalesOrderSearch($query, '42');

        $bindings = $query->getBindings();
        $this->assertContains(42, $bindings);
        $this->assertContains('42%', $bindings);
        // Amount match for the same digits (e.g. order total 42.00).
        $this->assertContains(42.0, $bindings);
    }

    public function test_apply_sales_order_search_s_prefix_is_exact_order_num(): void
    {
        $query = DB::table('sales');
        SqlLikeSearch::applySalesOrderSearch($query, 'S0034');

        $this->assertSame([34], $query->getBindings());
    }

    public function test_apply_sales_order_search_client_sale_uuid_targets_fulfillment_meta(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $query = DB::table('sales');
        SqlLikeSearch::applySalesOrderSearch($query, $uuid);

        $bindings = $query->getBindings();
        $this->assertContains($uuid, $bindings);
        $this->assertContains($uuid.':%', $bindings);
        $sql = $query->toSql();
        $this->assertStringContainsString('fulfillment_meta', $sql);
    }

    public function test_apply_sales_order_search_prev_edit_uuid_targets_fulfillment_meta(): void
    {
        $query = DB::table('sales');
        SqlLikeSearch::applySalesOrderSearch($query, 'prev-edit-42');

        $bindings = $query->getBindings();
        $this->assertContains('prev-edit-42', $bindings);
        $this->assertContains('prev-edit-42:%', $bindings);
    }

    public function test_apply_product_search_matches_unit_price_amount(): void
    {
        $query = DB::table('products');
        SqlLikeSearch::applyProductSearch($query, '6300');

        $bindings = $query->getBindings();
        $this->assertContains(6300.0, $bindings);
        $this->assertContains('%6300%', $bindings);
    }

    public function test_apply_product_search_amount_with_thousands_separator(): void
    {
        $query = DB::table('products');
        SqlLikeSearch::applyProductSearch($query, '6,300');

        $bindings = $query->getBindings();
        $this->assertContains(6300.0, $bindings);
        // Whole money term — not split into tokens "6" and "300".
        $this->assertFalse(collect($bindings)->contains(fn ($b) => $b === '%6%'));
    }

    public function test_apply_product_search_multi_token_allows_price_token(): void
    {
        $query = DB::table('products');
        SqlLikeSearch::applyProductSearch($query, 'sugar 6300');

        $bindings = $query->getBindings();
        $this->assertContains('%sugar%', $bindings);
        $this->assertContains(6300.0, $bindings);
    }

    public function test_parse_amount_search_term(): void
    {
        $this->assertSame(1500.0, SqlLikeSearch::parseAmountSearchTerm('1500'));
        $this->assertSame(1500.5, SqlLikeSearch::parseAmountSearchTerm('1,500.50'));
        $this->assertSame(2500.0, SqlLikeSearch::parseAmountSearchTerm('KES 2500'));
        $this->assertNull(SqlLikeSearch::parseAmountSearchTerm('cooking oil'));
    }

    public function test_apply_product_search_uses_ngram_fulltext_when_forced(): void
    {
        SqlLikeSearch::forceProductNameFulltext(true);
        try {
            $query = DB::table('products');
            SqlLikeSearch::applyProductSearch($query, 'sugar');
            $sql = strtolower($query->toSql());
            $this->assertStringContainsString('match(', $sql);
            $this->assertStringContainsString('against(', $sql);
            $this->assertContains('sugar', $query->getBindings());
            $this->assertContains('%sugar%', $query->getBindings());
        } finally {
            SqlLikeSearch::forceProductNameFulltext(null);
            SqlLikeSearch::resetProductNameFulltextCache();
        }
    }

    public function test_apply_customer_search_uses_substring_on_all_fields(): void
    {
        $query = DB::table('customers');
        SqlLikeSearch::applyCustomerSearch($query, '2547');

        $bindings = $query->getBindings();
        $this->assertContains(2547, $bindings);
        $this->assertTrue(
            collect($bindings)->filter(fn ($binding) => $binding === '%2547%')->isNotEmpty(),
        );
    }

    public function test_apply_customer_search_name_uses_name_and_contact_fields(): void
    {
        $query = DB::table('customers');
        SqlLikeSearch::applyCustomerSearch($query, 'Wanjiku');

        $bindings = $query->getBindings();
        $this->assertContains('%Wanjiku%', $bindings);
        $this->assertGreaterThanOrEqual(4, count($bindings));
    }

    public function test_empty_search_term_is_no_op(): void
    {
        $query = DB::table('products');
        SqlLikeSearch::applyProductSearch($query, '   ');

        $this->assertSame([], $query->getBindings());
        $this->assertInstanceOf(Builder::class, $query);
    }
}
