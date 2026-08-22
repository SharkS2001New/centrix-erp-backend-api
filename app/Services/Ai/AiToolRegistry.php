<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Services\Ai\Tools\AiToolInterface;
use App\Services\Ai\Tools\GetSalesByCashierTool;
use App\Services\Ai\Tools\GetSalesSummaryTool;
use InvalidArgumentException;

class AiToolRegistry
{
    /** @var array<string, AiToolInterface>|null */
    protected ?array $tools = null;

    public function __construct(
        protected GetSalesSummaryTool $getSalesSummary,
        protected GetSalesByCashierTool $getSalesByCashier,
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
    public function declarations(): array
    {
        return array_map(fn (AiToolInterface $tool) => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => $tool->parametersSchema(),
        ], $this->all());
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
            $this->getSalesSummary,
            $this->getSalesByCashier,
            // Incremental tools: get_stock_summary, get_customer_balance, …
        ];

        $this->tools = [];
        foreach ($registered as $tool) {
            $this->tools[$tool->name()] = $tool;
        }

        return $this->tools;
    }
}
