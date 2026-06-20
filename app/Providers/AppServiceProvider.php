<?php
namespace App\Providers;

use App\Models\PersonalAccessToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        if (
            $this->app->environment('production')
            && ! config('database.allow_destructive_commands', false)
        ) {
            DB::prohibitDestructiveCommands();
        }
    }
}
