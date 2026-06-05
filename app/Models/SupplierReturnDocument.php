<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierReturnDocument extends Model
{
    public $timestamps = false;

    protected $table = 'supplier_return_documents';

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'supplier_id', 'branch_id', 'source_type', 'lpo_no', 'supplier_invoice_no',
        'status', 'notes', 'reason_scope',
        'returned_by', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at',
        'rejection_reason',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierReturn::class, 'document_id');
    }
}
