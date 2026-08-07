<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'shelf_location', 'image_path', 'supplier_id', 'sell_on_retail', 'sell_on_bar', 'sell_on_hotel', 'vat_id', 'organization_id', 'branch_id',
        'reorder_point', 'low_stock_alert_enabled', 'created_by', 'updated_by',
        'deleted_at', 'deleted_by',
    ];

    protected $appends = ['image_url'];

    public function unit()
    {
        return $this->belongsTo(Uom::class, 'unit_id');
    }

    public function vat()
    {
        return $this->belongsTo(Vat::class, 'vat_id');
    }

    public function subCategory()
    {
        return $this->belongsTo(SubCategory::class, 'subcategory_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function catalogScope(): string
    {
        return $this->branch_id === null ? 'organization' : 'branch';
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->image_path) {
                return null;
            }

            $base = rtrim((string) config('app.url'), '/');
            $code = rawurlencode((string) $this->product_code);

            return $base.'/api/v1/products/'.$code.'/image/file';
        });
    }

    protected $casts = [
        'unit_price' => 'float',
        'stock_in_shop' => 'float',
        'stock_in_store' => 'float',
        'low_stock_alert_enabled' => 'boolean',
        'sell_on_retail' => 'boolean',
        'sell_on_bar' => 'boolean',
        'sell_on_hotel' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    /**
     * Suggest a unique 6-digit SKU for the organization (scan-code style).
     * Prefers the next number after existing 6-digit codes, then random free codes.
     */
    public static function generateNextProductCode(int $organizationId): string
    {
        $existing = static::query()
            ->where('organization_id', $organizationId)
            ->pluck('product_code')
            ->map(static fn ($code) => (string) $code)
            ->all();

        $used = array_fill_keys($existing, true);

        $maxSix = 99999;
        foreach ($existing as $code) {
            if (preg_match('/^\d{6}$/', $code) === 1) {
                $maxSix = max($maxSix, (int) $code);
            }
        }

        if ($maxSix < 999999) {
            $candidate = (string) ($maxSix + 1);
            if (! isset($used[$candidate])) {
                return $candidate;
            }
        }

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $candidate = (string) random_int(100000, 999999);
            if (! isset($used[$candidate])) {
                return $candidate;
            }
        }

        $start = random_int(100000, 999999);
        for ($offset = 0; $offset < 900000; $offset++) {
            $candidate = (string) (100000 + (($start - 100000 + $offset) % 900000));
            if (! isset($used[$candidate])) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Could not allocate a unique 6-digit product code.');
    }
}
