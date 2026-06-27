<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Branch;
use App\Models\Uom;
use App\Models\Vat;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'products';
    protected $primaryKey = 'product_code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'product_code', 'product_name', 'subcategory_id', 'unit_id', 'unit_price',
        'last_selling_price', 'last_cost_price', 'discount_type', 'discount_percentage',
        'discount_value', 'product_weight', 'product_volume_m3', 'stock_in_shop', 'stock_in_store',
        'supplier_id', 'sell_on_retail', 'vat_id', 'organization_id', 'branch_id',
        'reorder_point', 'low_stock_alert_enabled', 'created_by', 'updated_by',
        'deleted_at', 'deleted_by',
    ];

    public function unit()
    {
        return $this->belongsTo(Uom::class, 'unit_id');
    }

    public function vat()
    {
        return $this->belongsTo(Vat::class, 'vat_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function catalogScope(): string
    {
        return $this->branch_id === null ? 'organization' : 'branch';
    }

    protected $casts = [
        'unit_price' => 'float',
        'stock_in_shop' => 'float',
        'stock_in_store' => 'float',
        'low_stock_alert_enabled' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    public static function generateNextProductCode(int $organizationId): string
    {
        $codes = static::query()
            ->where('organization_id', $organizationId)
            ->pluck('product_code');

        $max = 0;
        foreach ($codes as $code) {
            if (preg_match('/^PRD#?(\d+)$/i', (string) $code, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return 'PRD#'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
