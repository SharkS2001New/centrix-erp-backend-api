<?php

namespace Database\Seeders;

use App\Services\Auth\RoleTemplateService;
use Illuminate\Database\Seeder;

class ProductionRoleSeeder extends Seeder
{
    public function run(): void
    {
        app(RoleTemplateService::class)->ensureAllRoles();
    }
}
