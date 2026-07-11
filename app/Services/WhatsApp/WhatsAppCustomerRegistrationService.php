<?php

namespace App\Services\WhatsApp;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\RouteModel;
use App\Models\User;
use App\Services\Customers\CustomerNumberAllocator;
use App\Services\Customers\CustomerRoutePolicy;
use App\Services\Customers\CustomerUniquenessValidator;
use App\Services\Erp\CapabilityGate;
use App\Support\OrganizationPublicStorage;
use App\Support\PhoneNumber;
use App\Support\UploadedImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class WhatsAppCustomerRegistrationService
{
    public function __construct(
        protected CustomerUniquenessValidator $uniqueness,
        protected CustomerRoutePolicy $customerRoutePolicy,
    ) {}

    public function requiresRoute(CapabilityGate $gate): bool
    {
        return $this->customerRoutePolicy->routeCustomersOnly($gate);
    }

    /**
     * Active routes for WhatsApp pick-list (capped for message size).
     *
     * @return list<array{id: int, route_name: string}>
     */
    public function listRoutes(int $organizationId, ?int $branchId = null, int $limit = 20): array
    {
        $query = RouteModel::query()
            ->where('organization_id', $organizationId)
            ->where(function ($q) {
                $q->where('is_active', true)->orWhereNull('is_active');
            })
            ->orderBy('route_name');

        if ($branchId) {
            $query->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            });
        }

        return $query
            ->limit($limit)
            ->get(['id', 'route_name'])
            ->map(fn (RouteModel $route) => [
                'id' => (int) $route->id,
                'route_name' => (string) $route->route_name,
            ])
            ->all();
    }

    /**
     * Create a customer from WhatsApp self-registration.
     *
     * @param  array{
     *   customer_name: string,
     *   phone: string,
     *   town?: string|null,
     *   route_id?: int|null,
     *   branch_id?: int|null,
     *   kra_pin?: string|null,
     *   latitude?: float|null,
     *   longitude?: float|null,
     *   shop_image_path?: string|null
     * }  $input
     * @return array{ok: true, customer: Customer}|array{ok: false, message: string}
     */
    public function register(
        ResolvedWhatsAppConfig $config,
        User $botUser,
        CapabilityGate $gate,
        array $input,
    ): array {
        $name = trim((string) ($input['customer_name'] ?? ''));
        if ($name === '' || mb_strlen($name) < 2) {
            return ['ok' => false, 'message' => 'Please reply with your shop or business name (at least 2 characters).'];
        }

        $phone = PhoneNumber::normalize((string) ($input['phone'] ?? ''));
        if ($phone === null) {
            return ['ok' => false, 'message' => 'We could not read your WhatsApp number. Please call the office to register.'];
        }

        $town = trim((string) ($input['town'] ?? ''));
        $town = $town !== '' && strtoupper($town) !== 'SKIP' ? $town : null;

        $kraPin = trim((string) ($input['kra_pin'] ?? ''));
        $kraPin = $kraPin !== '' && strtoupper($kraPin) !== 'SKIP' ? strtoupper($kraPin) : null;

        $latitude = $this->optionalLatitude($input['latitude'] ?? null);
        $longitude = $this->optionalLongitude($input['longitude'] ?? null);

        $branchId = $this->resolveBranchId($config, $botUser, isset($input['branch_id']) ? (int) $input['branch_id'] : null);
        if (! $branchId) {
            return ['ok' => false, 'message' => 'Registration is unavailable right now (no branch configured).'];
        }

        $pendingShopImage = isset($input['shop_image_path']) && is_string($input['shop_image_path'])
            ? trim($input['shop_image_path'])
            : '';
        $pendingShopImage = $pendingShopImage !== '' ? $pendingShopImage : null;

        $payload = [
            'customer_name' => $name,
            'phone_number' => $phone,
            'town' => $town,
            'kra_pin' => $kraPin,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'branch_id' => $branchId,
            'customer_type' => 'regular',
            'credit_limit' => 0,
            'route_id' => isset($input['route_id']) ? (int) $input['route_id'] : null,
        ];

        try {
            $payload = $this->customerRoutePolicy->applyDistributionCustomerRules($payload, $gate);
            $this->uniqueness->assertUnique(
                $config->organizationId,
                $payload['phone_number'],
                null,
                $kraPin,
            );

            $customer = DB::transaction(function () use ($config, $botUser, $payload, $pendingShopImage) {
                $payload['customer_num'] = app(CustomerNumberAllocator::class)
                    ->nextForOrganization($config->organizationId);
                $payload['organization_id'] = $config->organizationId;
                $payload['created_by'] = (int) $botUser->id;

                if ($pendingShopImage !== null) {
                    $finalPath = $this->promotePendingShopImage(
                        $config->organizationId,
                        (string) $payload['customer_num'],
                        $pendingShopImage,
                    );
                    if ($finalPath !== null) {
                        $payload['shop_image'] = $finalPath;
                    }
                }

                return Customer::query()->create($payload);
            });

            return ['ok' => true, 'customer' => $customer->fresh(['route'])];
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();

            return [
                'ok' => false,
                'message' => is_string($first) && $first !== ''
                    ? $first
                    : 'We could not complete registration with the details provided.',
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'ok' => false,
                'message' => 'Something went wrong while creating your account.',
            ];
        }
    }

    /**
     * Store a shop photo temporarily until the customer record is created.
     */
    public function storePendingShopImage(int $organizationId, string $phone, string $bytes, string $mime): ?string
    {
        $mime = strtolower(trim($mime));
        $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
        if (! in_array($mime, $allowed, true) || $bytes === '') {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'wa-shop-');
        if ($tmp === false) {
            return null;
        }

        $ext = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
        $pathWithExt = $tmp.'.'.$ext;
        @unlink($tmp);

        if (file_put_contents($pathWithExt, $bytes) === false) {
            return null;
        }

        try {
            $file = new UploadedFile($pathWithExt, 'shop.'.$ext, $mime, null, true);
            $directory = OrganizationPublicStorage::path($organizationId, 'whatsapp-pending');
            $stored = app(UploadedImageProcessor::class)->storePublicImage($file, $directory);

            return $stored['path'] ?? null;
        } catch (Throwable $e) {
            report($e);

            return null;
        } finally {
            @unlink($pathWithExt);
        }
    }

    public function discardPendingShopImage(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        try {
            Storage::disk('public')->delete($path);
        } catch (Throwable) {
            // best-effort cleanup
        }
    }

    public function officeContactLine(int $organizationId, ?int $preferredBranchId = null): string
    {
        $phone = $this->officePhone($organizationId, $preferredBranchId);
        if ($phone === null) {
            return 'Please call our office during business hours, or wait for a team member to contact you.';
        }

        return "Please call our office on *{$phone}*, or wait for a team member to contact you.";
    }

    public function officePhone(int $organizationId, ?int $preferredBranchId = null): ?string
    {
        if ($preferredBranchId) {
            $branchPhone = Branch::query()
                ->where('organization_id', $organizationId)
                ->where('id', $preferredBranchId)
                ->value('branch_phone');
            $normalized = $this->displayPhone($branchPhone);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $hqPhone = Branch::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN LOWER(COALESCE(branch_type, '')) IN ('hq','head office','headoffice') THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->value('branch_phone');
        $normalized = $this->displayPhone($hqPhone);
        if ($normalized !== null) {
            return $normalized;
        }

        $org = Organization::query()->find($organizationId);
        foreach (['primary_tel', 'secondary_tel', 'addn_tel1', 'addn_tel2'] as $field) {
            $normalized = $this->displayPhone($org?->{$field} ?? null);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    protected function promotePendingShopImage(int $organizationId, string $customerNum, string $pendingPath): ?string
    {
        $disk = Storage::disk('public');
        if (! $disk->exists($pendingPath)) {
            return null;
        }

        $ext = strtolower(pathinfo($pendingPath, PATHINFO_EXTENSION) ?: 'jpg');
        $final = OrganizationPublicStorage::path($organizationId, 'customers', $customerNum).'/shop.'.$ext;

        try {
            if ($disk->exists($final)) {
                $disk->delete($final);
            }
            $disk->makeDirectory(dirname($final));
            if (! $disk->move($pendingPath, $final)) {
                $disk->put($final, $disk->get($pendingPath));
                $disk->delete($pendingPath);
            }

            return $final;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    protected function resolveBranchId(ResolvedWhatsAppConfig $config, User $botUser, ?int $requested): ?int
    {
        if ($requested && $requested > 0) {
            $exists = Branch::query()
                ->where('organization_id', $config->organizationId)
                ->where('id', $requested)
                ->exists();
            if ($exists) {
                return $requested;
            }
        }

        if ($config->branchId) {
            return (int) $config->branchId;
        }

        if ($botUser->branch_id && (int) $botUser->organization_id === $config->organizationId) {
            return (int) $botUser->branch_id;
        }

        $first = Branch::query()
            ->where('organization_id', $config->organizationId)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');

        return $first ? (int) $first : null;
    }

    protected function displayPhone(?string $value): ?string
    {
        $normalized = PhoneNumber::normalize($value);
        if ($normalized === null || strlen($normalized) < 9) {
            return null;
        }

        return $normalized;
    }

    protected function optionalLatitude(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $n = (float) $value;
        if ($n < -90 || $n > 90) {
            return null;
        }

        return $n;
    }

    protected function optionalLongitude(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $n = (float) $value;
        if ($n < -180 || $n > 180) {
            return null;
        }

        return $n;
    }
}
