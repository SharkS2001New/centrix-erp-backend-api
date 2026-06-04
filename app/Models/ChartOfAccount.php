<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ChartOfAccount extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = "chart_of_accounts";
    protected $fillable = array (
  0 => 'organization_id',
  1 => 'account_code',
  2 => 'account_name',
  3 => 'account_type',
  4 => 'parent_id',
  5 => 'is_active',
);
}
