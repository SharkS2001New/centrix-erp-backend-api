<?php

namespace App\Models\Concerns;

use App\Models\Product;
use App\Models\Relations\BelongsToProductByOrganization;

trait BelongsToOrganizationProduct
{
    public function product(): BelongsToProductByOrganization
    {
        $instance = $this->newRelatedInstance(Product::class);

        return new BelongsToProductByOrganization(
            $instance->newQuery(),
            $this,
            'product_code',
            'product_code',
            'product'
        );
    }
}
