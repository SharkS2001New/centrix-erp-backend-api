<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

/**
 * Covers the regressions oracle flagged on the 2026-11-07 company-code
 * normalization change:
 *   1. PHP and SQL normalizers agree on lowercase / mixed-case / non-alphanumeric
 *      input (previous PHP regex `/[^A-Z0-9]/` stripped lowercase letters first,
 *      so `moon-co` collapsed to '' while SQL produced `MOONCO`).
 *   2. company_code_normalized is INVISIBLE — hidden from SELECT * and from JSON
 *      responses that serialize an Organization model.
 *   3. Normalized collisions during provisioning return 422 ValidationException,
 *      not a 500 raised by the UNIQUE index.
 *   4. Punctuation-only codes that normalize to '' are rejected by
 *      assertCodeAvailable before reaching the DB.
 */
class OrganizationCompanyCodeNormalizedTest extends TestCase
{
    use RefreshesErpDatabase;

    /** @dataProvider normalizerInputs */
    public function test_php_normalizer_matches_canonical_form(string $input, string $expected): void
    {
        $this->assertSame($expected, Organization::normalizeCompanyCodeIdentifier($input));
    }

    public static function normalizerInputs(): array
    {
        return [
            'uppercase alphanumeric is a no-op' => ['MOON', 'MOON'],
            'lowercase is uppercased' => ['moon', 'MOON'],
            'mixed case is uppercased' => ['Moon', 'MOON'],
            'hyphen is stripped' => ['MOON-CO', 'MOONCO'],
            'lowercase with hyphen' => ['moon-co', 'MOONCO'],
            'mixed case with dot' => ['Moon.Co', 'MOONCO'],
            'underscore stripped' => ['MOON_CO', 'MOONCO'],
            'spaces stripped and trimmed' => ['  MO ON ', 'MOON'],
            'all non-alphanumeric stripped' => ['M@O#O$N%', 'MOON'],
            // Unicode: PHP non-/u regex strips each byte of the multi-byte
            // character individually; MySQL REGEXP_REPLACE on utf8mb4 strips
            // the character. Both end up with only the ASCII alphanumerics,
            // so PHP and SQL agree on MOONCO.
            'multi-byte accented char is stripped' => ['MOON-é-CO', 'MOONCO'],
            'punctuation-only normalizes to empty' => ['---', ''],
            'empty stays empty' => ['', ''],
        ];
    }

    public function test_company_code_normalized_is_hidden_from_default_select(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();

        // Default SELECT * should not surface the generated column.
        $attributes = $org->fresh()->getAttributes();
        $this->assertArrayNotHasKey('company_code_normalized', $attributes);

        // Neither should the JSON serialization used by controllers that
        // return Organization models directly (e.g. OrganizationProvisionController).
        $json = $org->fresh()->toArray();
        $this->assertArrayNotHasKey('company_code_normalized', $json);
    }

    public function test_company_code_normalized_absent_from_provisioning_http_response(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        // The real contract: the HTTP response from OrganizationProvisionController::store
        // returns `'organization' => $org` directly. If INVISIBLE ever regresses
        // (or someone adds an explicit $visible/$appends), the generated column
        // would leak into the response JSON and break this assertion.
        $response = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'INVISCHECK',
            'org_name' => 'Invisibility Check',
            'org_email' => 'invis@example.com',
            'primary_tel' => '0711000044',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'enabled_modules' => ['sales' => true, 'sales.pos' => true, 'admin' => true],
            'admin_username' => 'invis_admin',
            'admin_email' => 'invis@example.com',
            'admin_password' => 'Password123',
            'admin_full_name' => 'Invis Admin',
        ])->assertCreated();

        // Pre-assert the organization object actually exists in the response
        // with the expected code — otherwise a future response that drops the
        // whole `organization` key would make the missing-path assertion pass
        // vacuously and hide a real regression.
        $response->assertJsonPath('organization.company_code', 'INVISCHECK');
        $response->assertJsonMissingPath('organization.company_code_normalized');
    }

    public function test_company_code_normalized_is_still_queryable_by_name(): void
    {
        // Explicit column reference must still work even though the column
        // is INVISIBLE — that is the whole point of the fast-path query.
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();

        $resolved = Organization::query()
            ->where('company_code_normalized', 'DEMO')
            ->first();

        $this->assertNotNull($resolved);
        $this->assertSame($org->id, $resolved->id);
    }

    public function test_find_by_company_code_identifier_hits_fast_path_for_lowercase_input(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();

        // Before the fix: PHP normalizer returned '' for 'demo' so this
        // returned null. After: matches via company_code_normalized = 'DEMO'.
        $resolved = Organization::findByCompanyCodeIdentifier('demo');
        $this->assertNotNull($resolved);
        $this->assertSame($org->id, $resolved->id);

        // Also matches for mixed-case and punctuated input.
        $this->assertSame($org->id, Organization::findByCompanyCodeIdentifier('De.Mo')?->id);
        $this->assertSame($org->id, Organization::findByCompanyCodeIdentifier('D-E-M-O')?->id);
    }

    public function test_mysql_generated_column_strips_unicode_consistently_with_php(): void
    {
        // Create directly (bypasses provisioning validator which would also
        // accept this today, but we are testing the MySQL side of the contract).
        // STORED generated column: MySQL computes company_code_normalized on
        // insert. Must match the PHP normalizer output for the same input.
        $org = Organization::create([
            'company_code' => 'MOON-é-CO',
            'org_name' => 'Unicode Co',
            'org_email' => 'unicode@example.com',
            'primary_tel' => '0711000055',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'registration_source' => 'platform_created',
        ]);

        // Read the generated column explicitly (INVISIBLE so not in SELECT *).
        $stored = Organization::query()
            ->where('id', $org->id)
            ->value('company_code_normalized');

        $this->assertSame('MOONCO', $stored);
        $this->assertSame(
            Organization::normalizeCompanyCodeIdentifier('MOON-é-CO'),
            $stored,
            'PHP and MySQL normalizers must agree on Unicode input',
        );

        // Fast-path lookup must resolve the row from a Unicode-containing
        // identifier — this is the whole reason the column exists.
        $resolved = Organization::findByCompanyCodeIdentifier('moon-é-co');
        $this->assertNotNull($resolved);
        $this->assertSame($org->id, $resolved->id);
    }

    public function test_provisioning_rejects_normalized_collision_with_422(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        // Seed MOON-CO
        $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'MOON-CO',
            'org_name' => 'Moon Co',
            'org_email' => 'moon@example.com',
            'primary_tel' => '0711000001',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'enabled_modules' => ['sales' => true, 'sales.pos' => true, 'admin' => true],
            'admin_username' => 'moon_admin',
            'admin_email' => 'moon@example.com',
            'admin_password' => 'Password123',
            'admin_full_name' => 'Moon Admin',
        ])->assertCreated();

        // MOON_CO normalizes to the same MOONCO — must be rejected as 422,
        // not propagate the DB UNIQUE violation as 500.
        $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'MOON_CO',
            'org_name' => 'Moon Co Underscore',
            'org_email' => 'moon2@example.com',
            'primary_tel' => '0711000002',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'enabled_modules' => ['sales' => true, 'sales.pos' => true, 'admin' => true],
            'admin_username' => 'moon_admin2',
            'admin_email' => 'moon2@example.com',
            'admin_password' => 'Password123',
            'admin_full_name' => 'Moon Admin 2',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['company_code']);
    }

    public function test_provisioning_rejects_punctuation_only_code_before_db(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        // `---` normalizes to '' — assertCodeAvailable throws 422 before
        // Organization::create() ever runs. Confirms the validation gate
        // sits in front of the DB, not behind it.
        $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => '---',
            'org_name' => 'Hyphens Only',
            'org_email' => 'punct@example.com',
            'primary_tel' => '0711000003',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'enabled_modules' => ['sales' => true, 'sales.pos' => true, 'admin' => true],
            'admin_username' => 'punct_admin',
            'admin_email' => 'punct@example.com',
            'admin_password' => 'Password123',
            'admin_full_name' => 'Punct Admin',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['company_code']);
    }
}
