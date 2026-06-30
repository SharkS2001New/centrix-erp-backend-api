<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ExpenseGroup extends Model
{
    use HasFactory;
    protected $table = 'expense_groups';
    public $timestamps = false;
    protected $fillable = [
        'group_name',
        'organization_id',
    ];
}
