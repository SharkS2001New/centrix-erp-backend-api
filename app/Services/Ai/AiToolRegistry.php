<?php

namespace App\Services\Ai;

use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\Tools\AiToolInterface;
use App\Services\Ai\Tools\FindScreenTool;
use App\Services\Ai\Tools\GetDebtorsSummaryTool;
use App\Services\Ai\Tools\GetPurchasingOverviewTool;
use App\Services\Ai\Tools\GetRouteOrdersTool;
use App\Services\Ai\Tools\GetSalesBriefTool;
use App\Services\Ai\Tools\GetSalesByCashierTool;
use App\Services\Ai\Tools\GetSalesSummaryTool;
use App\Services\Ai\Tools\GetStockSummaryTool;
use App\Services\Ai\Tools\GetTillHealthTool;
use InvalidArgumentException;

class AiToolRegistry
{
    /** @var array<string, AiToolInterface>|null */
    protected ?array $tools = null;

    public function __construct(
        protected GetSalesSummaryTool $getSalesSummary,
        protected GetSalesByCashierTool $getSalesByCashier,
        protected GetSalesBriefTool $getSalesBrief,
        protected FindScreenTool $findScreen,
        protected GetStockSummaryTool $getStockSummary,
        protected GetPurchasingOverviewTool $getPurchasingOverview,
        protected GetDebtorsSummaryTool $getDebtorsSummary,
        protected GetTillHealthTool $getTillHealth,
        protected GetRouteOrdersTool $getRouteOrders,
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
            $this->getSalesSummary,
            $this->getSalesByCashier,
            $this->getSalesBrief,
            $this->getStockSummary,
            $this->getPurchasingOverview,
            $this->getDebtorsSummary,
            $this->getTillHealth,
            $this->getRouteOrders,
        ];

        $this->tools = [];
        foreach ($registered as $tool) {
            $this->tools[$tool->name()] = $tool;
        }

        return $this->tools;
    }
}
