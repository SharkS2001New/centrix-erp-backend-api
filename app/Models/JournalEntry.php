<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class JournalEntry extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = "journal_entries";
    public function lines()
    {
        return $this->hasMany(JournalEntryLine::class, 'journal_entry_id');
    }

    protected $fillable = array (
  0 => 'organization_id',
  1 => 'branch_id',
  2 => 'entry_number',
  3 => 'entry_date',
  4 => 'reference_type',
  5 => 'reference_id',
  6 => 'description',
  7 => 'created_by',
);
}
