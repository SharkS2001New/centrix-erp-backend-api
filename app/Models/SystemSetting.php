<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SystemSetting extends Model
{
    use HasFactory;

    protected $table = 'system_settings';
    protected $fillable = [
        'organization_id', 'allow_below_stock', 'global_low_stock_threshold',
        'stock_alert_mode', 'mail_host', 'mail_user', 'mail_password', 'mail_port',
        'mail_from', 'admin_email', 'backup_folder_path', 'customer_debtor_message',
        'mpesa_callback_url', 'equity_callback_url', 'kra_device_callback_url',
    ];
    protected $hidden = ['mail_password'];
}
