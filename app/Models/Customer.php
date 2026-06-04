<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;

class Customer extends Model
{
    use HasFactory;

    protected $table = 'customers';
    protected $primaryKey = 'customer_num';
    public $incrementing = false;
    protected $fillable = [
        'customer_num', 'branch_id', 'organization_id', 'customer_name', 'customer_type',
        'phone_number', 'additional_phone', 'town', 'latitude', 'longitude', 'shop_image',
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

    protected function shopImageUrl(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->shop_image) {
                return null;
            }

            return Storage::disk('public')->url($this->shop_image);
        });
    }

    protected function hasLocation(): Attribute
    {
        return Attribute::get(fn () => $this->latitude !== null && $this->longitude !== null);
    }
}
