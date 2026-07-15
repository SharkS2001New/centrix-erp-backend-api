<?php
namespace App\Models;

use App\Models\Concerns\BelongsToOrganizationCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Sale extends Model
{
    use HasFactory;
    use BelongsToOrganizationCustomer;

    protected $table = 'sales';
    const UPDATED_AT = null;

    public function items()
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }

    public function payments()
    {
        return $this->hasMany(SalePayment::class, 'sale_id');
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function dispatchTrips()
    {
        return $this->belongsToMany(DispatchTrip::class, 'dispatch_trip_sales', 'sale_id', 'trip_id')
            ->withPivot('stop_seq')
            ->orderBy('dispatch_trip_sales.stop_seq');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    protected $fillable = [
        'order_num', 'branch_id', 'organization_id', 'channel', 'order_source', 'till_id',
        'float_session_id', 'cashier_id', 'customer_num', 'customer_name_override',
        'route_id', 'required_date', 'delivery_date', 'status', 'total_vat', 'order_total', 'order_discount',
        'voucher_payment_amount', 'points_payment_amount', 'loyalty_card_id',
        'cash', 'mpesa_amount', 'equity_amount', 'kcb_amount', 'order_change',
        'payment_status', 'amount_paid', 'fulfillment_meta',
        'payment_method_code', 'is_credit_sale', 'stock_balanced', 'receipt_printed',
        'comments', 'archived', 'deleted_by', 'deleted_at', 'completed_at',
        'cancelled_at', 'cancelled_by', 'expired_at', 'expired_by',
    ];
    protected $casts = [
        'fulfillment_meta' => 'array',
        'amount_paid' => 'decimal:2',
        'required_date' => 'date',
        'delivery_date' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expired_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function isLegacyImport(): bool
    {
        return (bool) ($this->fulfillment_meta['legacy_import'] ?? false);
    }

    /** @return 'online'|'offline'|null */
    public function mobileOrderConnectivity(): ?string
    {
        if ($this->channel !== 'mobile') {
            return null;
        }

        $offline = data_get($this->fulfillment_meta, 'location_check.offline_order');
        if ($offline === null) {
            return null;
        }

        return filter_var($offline, FILTER_VALIDATE_BOOLEAN) ? 'offline' : 'online';
    }

    public function isOfflineMobileOrder(): bool
    {
        return $this->mobileOrderConnectivity() === 'offline';
    }

    /**
     * Display name for this order. Registered customers use the org-scoped
     * customer relation (customer_num is only unique within an organization).
     * Walk-in / free-text names use customer_name_override.
     */
    public function customerDisplayName(): string
    {
        if ($this->customer_num) {
            if ($this->relationLoaded('customer')) {
                $related = trim((string) ($this->customer?->customer_name ?? ''));
                if ($related !== '') {
                    return $related;
                }
            } else {
                $related = trim((string) ($this->customer()?->first()?->customer_name ?? ''));
                if ($related !== '') {
                    return $related;
                }
            }
        }

        $override = trim((string) ($this->customer_name_override ?? ''));
        if ($override !== '') {
            return $override;
        }

        if ($this->customer_num) {
            return 'Customer #'.$this->customer_num;
        }

        return 'Walk-in';
    }

    public function scopeCentrixMetrics($query)
    {
        return \App\Services\Sales\CentrixSalesScope::excludeLegacyMaterialized($query);
    }

    public function scopeLegacyMaterialized($query)
    {
        return $query->where('fulfillment_meta->legacy_import', true);
    }
}
