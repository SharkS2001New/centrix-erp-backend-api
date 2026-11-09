<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use App\Models\Branch;
use App\Models\Uom;
use App\Models\Vat;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

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

    protected $appends = ['image_url', 'image_version'];

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

    /** Fingerprint of image_path — tills use this to keep local caches until the photo changes. */
    protected function imageVersion(): Attribute
    {
        return Attribute::get(function () {
            $path = is_string($this->image_path) ? trim($this->image_path) : '';
            if ($path === '') {
                return null;
            }

            return substr(hash('sha256', $path), 0, 16);
        });
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->image_path) {
                return null;
            }

            $version = $this->image_version;
            if (filter_var($this->image_path, FILTER_VALIDATE_URL)) {
                // Keep remote/CDN/R2 URLs intact. Appending ?v= breaks signed query strings
                // and some hotlink rules; tills already cache-bust via image_version.
                return $this->image_path;
            }

            // Prefer public disk URL (local /storage or R2 CDN) so tills can use <img>
            // without authenticated /image/file fan-out on every tile.
            $path = ltrim(str_replace('\\', '/', (string) $this->image_path), '/');
            try {
                $url = Storage::disk('public')->url($path);
            } catch (\Throwable) {
                $url = null;
            }

            if (is_string($url) && $url !== '') {
                if (! filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, '/')) {
                    $url = rtrim((string) config('app.url'), '/').$url;
                }
                if (is_string($version) && $version !== '' && ! str_contains($url, 'v=')) {
                    $url .= (str_contains($url, '?') ? '&' : '?').'v='.rawurlencode($version);
                }

                return $url;
            }

            $base = rtrim((string) config('app.url'), '/');
            $code = rawurlencode((string) $this->product_code);
            $fallback = $base.'/api/v1/products/'.$code.'/image/file';
            if (is_string($version) && $version !== '') {
                $fallback .= '?v='.$version;
            }

            return $fallback;
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
     * Includes soft-deleted rows so a deleted SKU is never re-suggested (unique key still holds it).
     */
    public static function generateNextProductCode(int $organizationId): string
    {
        $existing = static::withTrashed()
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
