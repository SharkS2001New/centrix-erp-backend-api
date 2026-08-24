<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SaleItem extends Model
{
    use HasFactory;

    protected $table = 'sale_items';
    public $timestamps = false;
    protected $fillable = [
        'sale_id', 'product_code', 'product_name', 'line_no', 'item_code', 'quantity', 'uom',
        'selling_price', 'display_unit_price', 'discount_given', 'product_vat', 'amount', 'on_wholesale_retail',
    ];

    public function product()
    {
        // Include soft-deleted catalogue rows so historical sales still resolve a name
        // when product_name was not snapshotted (legacy rows).
        return $this->belongsTo(Product::class, 'product_code', 'product_code')->withTrashed();
    }

    /**
     * Human-readable product label for receipts / KRA PLU lines.
     * Prefer the sale-line snapshot unless it is empty or identical to the SKU
     * (legacy rows often stored product_code as product_name).
     */
    public function resolvedProductName(): string
    {
        $code = trim((string) ($this->product_code ?? ''));
        $snap = trim((string) ($this->product_name ?? ''));
        if ($snap !== '' && ($code === '' || strcasecmp($snap, $code) !== 0)) {
            return $snap;
        }

        $fromProduct = trim((string) ($this->product?->product_name ?? ''));
        if ($fromProduct !== '') {
            return $fromProduct;
        }

        if ($snap !== '') {
            return $snap;
        }

        return $code !== '' ? $code : 'Product';
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }
}
