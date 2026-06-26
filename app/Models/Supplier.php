<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Supplier extends Model
{
    use HasFactory;

    protected $table = 'suppliers';
    protected $fillable = [
        'supplier_code', 'supplier_name', 'contact_person', 'email', 'phone',
        'alternate_phone', 'address', 'town', 'tax_pin', 'additional_info',
        'contacts', 'organization_id', 'is_active', 'created_by', 'deleted_by', 'deleted_at',
    ];
    protected $casts = ['contacts' => 'array', 'is_active' => 'boolean', 'deleted_at' => 'datetime'];

    public static function generateNextSupplierCode(int $organizationId): string
    {
        $codes = static::query()
            ->where('organization_id', $organizationId)
            ->pluck('supplier_code');

        $max = 0;
        foreach ($codes as $code) {
            if (preg_match('/^SUP-(\d+)$/i', (string) $code, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return 'SUP-'.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }
}
