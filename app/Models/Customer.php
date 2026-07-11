<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'customers';
    protected $primaryKey = 'id';
    protected $fillable = [
        'customer_num', 'branch_id', 'organization_id', 'customer_name', 'customer_type',
        'phone_number', 'additional_phone', 'email', 'town', 'latitude', 'longitude', 'shop_image',
        'route_id', 'created_by',
        'customer_status', 'kra_pin', 'terms_of_payment', 'credit_limit',
        'current_balance', 'deleted_by', 'deleted_at',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'credit_limit' => 'decimal:2',
        'current_balance' => 'decimal:2',
    ];

    protected $appends = ['shop_image_url', 'has_location'];

    public function route(): BelongsTo
    {
        return $this->belongsTo(RouteModel::class, 'route_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    protected function shopImageUrl(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->shop_image) {
                return null;
            }

            $base = rtrim((string) config('app.url'), '/');

            return $base.'/api/v1/customers/'.$this->customer_num.'/shop-image/file';
        });
    }

    protected function hasLocation(): Attribute
    {
        return Attribute::get(fn () => $this->latitude !== null && $this->longitude !== null);
    }
}
