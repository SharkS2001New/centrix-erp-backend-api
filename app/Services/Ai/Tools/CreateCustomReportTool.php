<?php

namespace App\Services\Ai\Tools;

use App\Models\CustomReportTemplate;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use App\Services\Reports\ReportBuilderService;
use App\Services\Reports\ReportBuilderSuggestService;
use Illuminate\Validation\ValidationException;

/**
 * Create a Centrix report-builder template and return a clickable /reports/custom/{id} link.
 */
class CreateCustomReportTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
        protected ReportBuilderSuggestService $suggest,
        protected ReportBuilderService $builder,
    ) {}

    public function name(): string
    {
        return 'create_custom_report';
    }

    public function description(): string
    {
        return 'Create a Centrix custom report (report builder) and return its path. '
            .'If the user has not chosen a report name yet, call this WITHOUT name — the tool returns needs_name=true; '
            .'then ask "What should I name this report?" and call again with their chosen name. '
            .'Pass a clear instruction (e.g. "employee attendance with check in and hours" or "daily sales by cashier"). '
            .'After creation, reply with the path like /reports/custom/123 so the UI can open it.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Exact report title chosen by the user. Omit to ask for a name first.',
                ],
                'instruction' => [
                    'type' => 'string',
                    'description' => 'What the report should show (data source, columns, grouping).',
                ],
                'workspace_id' => [
                    'type' => 'string',
                    'enum' => ['backoffice', 'hr', 'accounting', 'distribution', 'admin'],
                    'description' => 'Optional. Prefer hr for attendance/payroll, accounting for GL, otherwise omit.',
                ],
            ],
            'required' => ['instruction'],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $organization = $this->resolveOrganizationForUser($user);
        if (! $organization) {
            throw ValidationException::withMessages([
                'organization' => ['Your account is not linked to an organization.'],
            ]);
        }

        if (! $this->assertSameOrganization($user, $organization)) {
            throw ValidationException::withMessages([
                'organization' => ['You cannot create reports for another organization.'],
            ]);
        }

        $gate = $this->erp->gateForUser($user);
        $canCreate = $this->permissions->hasPermission($user, 'ai.assist', $gate)
            || $this->permissions->hasPermission($user, 'reports.builder.create', $gate)
            || $this->permissions->hasPermission($user, 'reports.builder.view', $gate);
        if (! $canCreate) {
            return [
                'error' => true,
                'message' => 'You do not have permission to create custom reports.',
                'screens' => [
                    ['label' => 'Report builder', 'path' => '/reports/builder'],
                ],
            ];
        }

        $instruction = trim((string) ($arguments['instruction'] ?? ''));
        if ($instruction === '') {
            return [
                'created' => false,
                'needs_instruction' => true,
                'ask' => 'What should this report show? For example: daily sales by cashier, or employee attendance this month.',
            ];
        }

        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '' && preg_match('/(?:named|called|call it|name it|title)\s+["\']?([^"\'\n.]{2,80})["\']?/i', $instruction, $m)) {
            $name = trim($m[1]);
        }

        if ($name === '') {
            return [
                'created' => false,
                'needs_name' => true,
                'ask' => 'What should I name this report?',
                'instruction' => $instruction,
                'suggested_names' => $this->suggestedNames($instruction),
                'tip' => 'Ask the user to pick a name, then call create_custom_report again with that name and the same instruction.',
            ];
        }

        $workspaceId = trim((string) ($arguments['workspace_id'] ?? ''));
        if ($workspaceId === '') {
            $workspaceId = $this->inferWorkspace($instruction);
        }

        try {
            $draft = $this->suggest->localDraft($user, $instruction, $workspaceId);
            $spec = is_array($draft['spec'] ?? null) ? $draft['spec'] : [];
            if ($spec === [] || empty($spec['columns'])) {
                $spec = $this->defaultSpec($instruction, $workspaceId);
            }
            $spec = $this->builder->validateSpec($spec, $workspaceId);
        } catch (\Throwable $e) {
            try {
                $spec = $this->builder->validateSpec($this->defaultSpec($instruction, $workspaceId), $workspaceId);
            } catch (\Throwable $inner) {
                return [
                    'error' => true,
                    'message' => 'Could not build that report from the description. Try being more specific, or open /reports/builder.',
                    'detail' => $inner->getMessage(),
                    'screens' => [
                        ['label' => 'Report builder', 'path' => '/reports/builder'],
                    ],
                ];
            }
        }

        $template = CustomReportTemplate::query()->create([
            'organization_id' => $organization->id,
            'created_by' => $user->id,
            'name' => mb_substr($name, 0, 200),
            'description' => mb_substr($instruction, 0, 2000),
            'spec' => $spec,
            'is_shared' => false,
        ]);

        $path = '/reports/custom/'.$template->id;

        return [
            'created' => true,
            'name' => $template->name,
            'path' => $path,
            'workspace_id' => $workspaceId,
            'message' => 'Report saved. Give the user this link: '.$path,
            'screens' => [
                ['label' => $template->name, 'path' => $path],
                ['label' => 'Report builder', 'path' => '/reports/builder'],
            ],
        ];
    }

    protected function inferWorkspace(string $instruction): string
    {
        $text = mb_strtolower($instruction);
        if ($this->textHasAny($text, ['attendance', 'payroll', 'employee', 'leave', 'lateness', 'absent', 'headcount', 'nssf', 'staff'])) {
            return 'hr';
        }
        if ($this->textHasAny($text, ['journal', 'ledger', 'trial balance', 'balance sheet', 'profit and loss', 'expense', 'accounts receivable', 'accounts payable'])) {
            return 'accounting';
        }
        if ($this->textHasAny($text, ['dispatch', 'trip', 'driver', 'pod', 'route sales', 'fulfillment'])) {
            return 'distribution';
        }

        return 'backoffice';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultSpec(string $instruction, string $workspaceId): array
    {
        $source = $this->inferSource($instruction, $workspaceId);
        $fields = config("report_builder.sources.{$source}.fields", []);
        $columns = [];
        foreach (array_keys($fields) as $fieldKey) {
            if (count($columns) >= 6) {
                break;
            }
            $columns[] = [
                'source' => $source,
                'field' => $fieldKey,
                'label' => $fields[$fieldKey]['label'] ?? $fieldKey,
            ];
        }

        return [
            'source' => $source,
            'sources' => [$source],
            'columns' => $columns,
            'group_by' => [],
        ];
    }

    protected function inferSource(string $instruction, string $workspaceId): string
    {
        $text = mb_strtolower($instruction);
        $allowed = array_flip($this->builder->allowedSourceKeys($workspaceId));

        $preferred = [];
        if ($this->textHasAny($text, ['attendance', 'clock', 'check in', 'check-in', 'absent', 'late'])) {
            $preferred[] = 'attendance';
        }
        if ($this->textHasAny($text, ['payroll', 'salary', 'payslip'])) {
            $preferred[] = 'payroll_lines';
        }
        if ($this->textHasAny($text, ['employee', 'staff', 'headcount'])) {
            $preferred[] = 'employees';
        }
        if ($this->textHasAny($text, ['stock', 'inventory', 'on hand'])) {
            $preferred[] = 'stock';
        }
        if ($this->textHasAny($text, ['customer', 'debtor'])) {
            $preferred[] = 'customers';
        }
        if ($this->textHasAny($text, ['product', 'sku', 'item sold'])) {
            $preferred[] = 'sale_items';
        }
        $preferred[] = 'sales';

        foreach ($preferred as $key) {
            if (isset($allowed[$key])) {
                return $key;
            }
        }

        $keys = array_keys($allowed);

        return $keys[0] ?? 'sales';
    }

    /**
     * @return list<string>
     */
    protected function suggestedNames(string $instruction): array
    {
        $base = trim(preg_replace('/\s+/', ' ', $instruction) ?? '');
        $base = mb_substr($base, 0, 40);
        if ($base === '') {
            return ['Custom report', 'Operations report'];
        }

        return [
            ucwords($base),
            $base.' report',
        ];
    }

    /** @param  list<string>  $needles */
    protected function textHasAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
