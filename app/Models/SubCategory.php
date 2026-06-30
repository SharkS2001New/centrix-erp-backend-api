<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SubCategory extends Model
{
    use HasFactory;

    protected $table = 'sub_categories';
    public $timestamps = false;
    protected $fillable = ['category_id', 'subcategory_name', 'organization_id', 'created_by'];
}
