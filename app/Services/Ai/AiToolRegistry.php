<?php

namespace App\Services\Ai;

use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\Tools\AiToolInterface;
use App\Services\Ai\Tools\CalculateScenarioTool;
use App\Services\Ai\Tools\CreateCustomReportTool;
use App\Services\Ai\Tools\FindCatalogueExceptionsTool;
use App\Services\Ai\Tools\FindScreenTool;
use App\Services\Ai\Tools\GetCashPositionTool;
use App\Services\Ai\Tools\GetCustomerPortfolioTool;
use App\Services\Ai\Tools\GetCustomerReturnsTool;
use App\Services\Ai\Tools\GetCustomerStatementTool;
use App\Services\Ai\Tools\GetDebtorsSummaryTool;
use App\Services\Ai\Tools\GetEmployeeAttendanceTool;
use App\Services\Ai\Tools\GetEmployeeDetailsTool;
use App\Services\Ai\Tools\GetEmployeePayrollPreviewTool;
use App\Services\Ai\Tools\GetExpenseSummaryTool;
use App\Services\Ai\Tools\GetInventoryValuationTool;
use App\Services\Ai\Tools\GetProfitLossTool;
use App\Services\Ai\Tools\GetSupplierStatementTool;
use App\Services\Ai\Tools\SearchTrainingNotesTool;
use App\Services\Ai\Tools\GetProductDetailsTool;
use App\Services\Ai\Tools\GetProductPriceHistoryTool;
use App\Services\Ai\Tools\GetLpoDetailsTool;
use App\Services\Ai\Tools\GetPurchasingOverviewTool;
use App\Services\Ai\Tools\GetRouteDetailsTool;
use App\Services\Ai\Tools\GetRouteOrdersTool;
use App\Services\Ai\Tools\GetSalesBriefTool;
use App\Services\Ai\Tools\GetSalesByCashierTool;
use App\Services\Ai\Tools\GetSalesByProductTool;
use App\Services\Ai\Tools\GetSalesSummaryTool;
use App\Services\Ai\Tools\GetStockSummaryTool;
use App\Services\Ai\Tools\GetTillHealthTool;
use App\Services\Ai\Tools\GetUserDetailsTool;
use App\Services\Ai\Tools\GetVatCollectedTool;
use App\Services\Ai\Tools\RunInsightTool;
use InvalidArgumentException;

class AiToolRegistry
{
    /** @var array<string, AiToolInterface>|null */
    protected ?array $tools = null;

    public function __construct(
        protected GetSalesSummaryTool $getSalesSummary,
        protected GetVatCollectedTool $getVatCollected,
        protected GetSalesByCashierTool $getSalesByCashier,
        protected GetSalesByProductTool $getSalesByProduct,
        protected GetSalesBriefTool $getSalesBrief,
        protected FindScreenTool $findScreen,
        protected FindCatalogueExceptionsTool $findCatalogueExceptions,
        protected GetStockSummaryTool $getStockSummary,
        protected GetProductDetailsTool $getProductDetails,
        protected GetProductPriceHistoryTool $getProductPriceHistory,
        protected SearchTrainingNotesTool $searchTrainingNotes,
        protected GetPurchasingOverviewTool $getPurchasingOverview,
        protected GetLpoDetailsTool $getLpoDetails,
        protected GetDebtorsSummaryTool $getDebtorsSummary,
        protected GetCustomerStatementTool $getCustomerStatement,
        protected GetCustomerReturnsTool $getCustomerReturns,
        protected GetSupplierStatementTool $getSupplierStatement,
        protected GetTillHealthTool $getTillHealth,
        protected GetRouteOrdersTool $getRouteOrders,
        protected GetRouteDetailsTool $getRouteDetails,
        protected GetUserDetailsTool $getUserDetails,
        protected GetEmployeeAttendanceTool $getEmployeeAttendance,
        protected GetEmployeeDetailsTool $getEmployeeDetails,
        protected GetEmployeePayrollPreviewTool $getEmployeePayrollPreview,
        protected CreateCustomReportTool $createCustomReport,
        protected RunInsightTool $runInsight,
        protected GetProfitLossTool $getProfitLoss,
        protected GetExpenseSummaryTool $getExpenseSummary,
        protected GetCustomerPortfolioTool $getCustomerPortfolio,
        protected GetInventoryValuationTool $getInventoryValuation,
        protected GetCashPositionTool $getCashPosition,
        protected CalculateScenarioTool $calculateScenario,
    ) {}

    /**
     * @return list<AiToolInterface>
     */
    public function all(): array
    {
        return array_values($this->map());
    }

    public function get(string $name): AiToolInterface
    {
        $map = $this->map();
        if (! isset($map[$name])) {
            throw new InvalidArgumentException("Unknown AI tool [{$name}].");
        }

        return $map[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->map()[$name]);
    }

    /**
     * Tool definitions for Gemini / OpenAI function calling.
     *
     * @return list<array{name: string, description: string, parameters: array<string, mixed>}>
     */
    public function declarations(?Organization $organization = null): array
    {
        $tools = $organization
            ? $this->enabledForOrganization($organization)
            : $this->all();

        return array_map(fn (AiToolInterface $tool) => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => $tool->parametersSchema(),
        ], $tools);
    }

    /**
     * @return list<AiToolInterface>
     */
    public function enabledForOrganization(Organization $organization): array
    {
        $flags = AiSettingsResolver::forOrganization($organization)['tools'] ?? [];
        $defaults = config('ai.tools', []);

        return array_values(array_filter($this->all(), function (AiToolInterface $tool) use ($flags, $defaults) {
            $name = $tool->name();
            if (array_key_exists($name, $flags)) {
                return (bool) $flags[$name];
            }

            return (bool) ($defaults[$name] ?? true);
        }));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(string $name, User $user, array $arguments): array
    {
        if (! $this->has($name)) {
            return [
                'error' => true,
                'message' => 'That tool is not available.',
            ];
        }

        $organization = Organization::query()->find((int) $user->organization_id);
        if ($organization) {
            $enabledNames = array_map(fn (AiToolInterface $t) => $t->name(), $this->enabledForOrganization($organization));
            if (! in_array($name, $enabledNames, true)) {
                return [
                    'error' => true,
                    'message' => 'That tool is disabled for this organization.',
                ];
            }
        }

        try {
            return $this->get($name)->execute($user, $arguments);
        } catch (\Throwable $e) {
            report($e);

            return [
                'error' => true,
                'message' => 'Unable to load that data with your current permissions.',
            ];
        }
    }

    /**
     * @return array<string, AiToolInterface>
     */
    protected function map(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $registered = [
            $this->findScreen,
            $this->findCatalogueExceptions,
            $this->getSalesSummary,
            $this->getVatCollected,
            $this->getSalesByCashier,
            $this->getSalesByProduct,
            $this->getSalesBrief,
            $this->getStockSummary,
            $this->getProductDetails,
            $this->getProductPriceHistory,
            $this->searchTrainingNotes,
            $this->getPurchasingOverview,
            $this->getLpoDetails,
            $this->getDebtorsSummary,
            $this->getCustomerStatement,
            $this->getCustomerReturns,
            $this->getSupplierStatement,
            $this->getTillHealth,
            $this->getRouteOrders,
            $this->getRouteDetails,
            $this->getUserDetails,
            $this->getEmployeeAttendance,
            $this->getEmployeeDetails,
            $this->getEmployeePayrollPreview,
            $this->createCustomReport,
            $this->runInsight,
            $this->getProfitLoss,
            $this->getExpenseSummary,
            $this->getCustomerPortfolio,
            $this->getInventoryValuation,
            $this->getCashPosition,
            $this->calculateScenario,
        ];

        $this->tools = [];
        foreach ($registered as $tool) {
            $this->tools[$tool->name()] = $tool;
        }

        return $this->tools;
    }
}
