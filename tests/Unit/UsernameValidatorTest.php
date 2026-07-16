<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Auth\UsernameValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UsernameValidatorTest extends TestCase
{
    public function test_duplicate_active_username_is_rejected(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        try {
            app(UsernameValidator::class)->assertUniqueInOrganization(
                (int) $admin->organization_id,
                'admin',
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(
                [UsernameValidator::DUPLICATE_MESSAGE],
                $e->errors()['username'] ?? null,
            );
        }
    }

    public function test_duplicate_soft_deleted_username_is_rejected_with_restore_hint(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $archived = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'dup_soft_delete_user',
            'full_name' => 'Archived',
            'password' => bcrypt('password123'),
            'access_scope' => 'branch',
            'is_active' => false,
        ]);
        $archived->delete();

        try {
            app(UsernameValidator::class)->assertUniqueInOrganization(
                (int) $admin->organization_id,
                'dup_soft_delete_user',
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(
                [UsernameValidator::DUPLICATE_DELETED_MESSAGE],
                $e->errors()['username'] ?? null,
            );
        } finally {
            User::withTrashed()->where('id', $archived->id)->forceDelete();
        }
    }
}
