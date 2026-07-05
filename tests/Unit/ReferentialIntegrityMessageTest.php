<?php

namespace Tests\Unit;

use App\Support\ReferentialIntegrityMessage;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class ReferentialIntegrityMessageTest extends TestCase
{
    public function test_maps_sub_category_delete_constraint_to_friendly_message(): void
    {
        $pdo = new class extends \PDOException
        {
            public function __construct()
            {
                parent::__construct(
                    'SQLSTATE[23000]: Integrity constraint violation: 1451 Cannot delete or update a parent row: '
                    .'a foreign key constraint fails (`centrix_erp`.`products`, CONSTRAINT `products_ibfk_1` '
                    .'FOREIGN KEY (`subcategory_id`) REFERENCES `sub_categories` (`id`))',
                    23000,
                );
                $this->errorInfo = ['23000', 1451, $this->getMessage()];
            }
        };

        $exception = new QueryException(
            'mysql',
            'delete from `sub_categories` where `id` = ?',
            [1],
            $pdo,
        );

        $message = ReferentialIntegrityMessage::forDelete($exception);

        $this->assertNotNull($message);
        $this->assertStringContainsString('product(s)', $message);
        $this->assertStringContainsString('sub-category', $message);
    }

    public function test_ignores_non_foreign_key_violations(): void
    {
        $exception = new QueryException(
            'mysql',
            'insert into `products` (`product_code`) values (?)',
            ['DUPE'],
            new \PDOException(
                'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry',
                23000,
            ),
        );

        $this->assertNull(ReferentialIntegrityMessage::forDelete($exception));
    }
}
