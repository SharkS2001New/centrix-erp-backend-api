<?php

namespace App\Exceptions;

use InvalidArgumentException;

class MissingProductWeightsException extends InvalidArgumentException
{
    /**
     * @param  array<int, array{product_code: string, product_name: string, quantity: float, product_weight: float|null}>  $products
     */
    public function __construct(
        string $message,
        public readonly array $products,
        public readonly float $totalWeightKg = 0.0,
    ) {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => 'missing_product_weights',
            'total_weight_kg' => round($this->totalWeightKg, 3),
            'products' => $this->products,
        ];
    }
}
