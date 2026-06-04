<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class JournalEntryLine extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = "journal_entry_lines";
    protected $fillable = array (
  0 => 'journal_entry_id',
  1 => 'account_id',
  2 => 'debit',
  3 => 'credit',
  4 => 'line_notes',
);
}
