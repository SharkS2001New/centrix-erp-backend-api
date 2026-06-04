<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class GeneratePostmanCollectionCommand extends Command
{
    protected $signature = 'postman:generate {--output=postman/POS-ERP-API.postman_collection.json}';

    protected $description = 'Generate Postman Collection v2.1 from all /api/v1 routes (incl. Sanctum login)';

    protected array $resourceFolders = [
        'auth' => 'Auth',
        'erp' => 'Platform',
        'organizations' => 'Admin',
        'branches' => 'Admin',
        'roles' => 'Admin',
        'permissions' => 'Admin',
        'users' => 'Admin',
        'system-settings' => 'Admin',
        'audit-logs' => 'Admin',
        'tills' => 'POS',
        'till-float-sessions' => 'POS',
        'sales' => 'Sales CRUD',
        'sale-items' => 'Sales CRUD',
        'sale-payments' => 'Sales CRUD',
        'temporary-carts' => 'Sales CRUD',
        'cart-lines' => 'Sales CRUD',
        'stock-reservations' => 'Sales CRUD',
        'payment-methods' => 'Payments',
        'customer-invoices' => 'Payments',
        'customer-invoice-payments' => 'Payments',
        'products' => 'Catalog',
        'categories' => 'Catalog',
        'sub-categories' => 'Catalog',
        'uoms' => 'Catalog',
        'vats' => 'Catalog',
        'retail-package-settings' => 'Catalog',
        'price-history' => 'Catalog',
        'suppliers' => 'Suppliers',
        'lpo-statuses' => 'Purchasing',
        'lpo-mst' => 'Purchasing',
        'lpo-txn' => 'Purchasing',
        'lpo-attachments' => 'Purchasing',
        'lpo-supplier-invoices' => 'Purchasing',
        'customers' => 'Customers',
        'routes' => 'Customers',
        'current-stock' => 'Inventory CRUD',
        'inventory-transactions' => 'Inventory CRUD',
        'damages' => 'Inventory CRUD',
        'stock-receipts' => 'Inventory CRUD',
        'supplier-returns' => 'Inventory CRUD',
        'stock-take-sessions' => 'Inventory CRUD',
        'stock-take-lines' => 'Inventory CRUD',
        'returns' => 'Inventory CRUD',
        'expense-groups' => 'Expenses',
        'expenses' => 'Expenses',
        'kra-responses' => 'Integrations',
        'chart-of-accounts' => 'Accounting',
        'journal-entries' => 'Accounting',
        'journal-entry-lines' => 'Accounting',
        'departments' => 'HR & Payroll',
        'employees' => 'HR & Payroll',
        'pay-periods' => 'HR & Payroll',
        'payroll-runs' => 'HR & Payroll',
        'payroll-lines' => 'HR & Payroll',
        'vehicles' => 'Fulfillment',
        'drivers' => 'Fulfillment',
    ];

    public function handle(): int
    {
        $output = base_path($this->option('output'));
        File::ensureDirectoryExists(dirname($output));

        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/v1'))
            ->sortBy(fn (Route $r) => $r->uri());

        $grouped = [];

        foreach ($routes as $route) {
            $folder = $this->folderForRoute($route);
            $methods = array_values(array_diff(array_map('strtoupper', $route->methods()), ['HEAD']));

            foreach ($methods as $method) {
                $grouped[$folder][] = $this->buildRequestItem($route, $method);
            }
        }

        ksort($grouped);

        $items = [];
        if (isset($grouped['Auth'])) {
            $items[] = $this->authFolder($grouped['Auth']);
            unset($grouped['Auth']);
        }
        foreach ($grouped as $name => $requests) {
            $items[] = [
                'name' => $name,
                'item' => $requests,
            ];
        }

        $collection = [
            'info' => [
                '_postman_id' => 'pos-erp-api-v3-collection',
                'name' => 'POS / ERP API v3',
                'description' => 'All `/api/v1` routes. Run **Auth → Login** first; the test script saves `token` to the environment. Regenerate: `php artisan postman:generate`.',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'auth' => [
                'type' => 'bearer',
                'bearer' => [
                    ['key' => 'token', 'value' => '{{token}}', 'type' => 'string'],
                ],
            ],
            'variable' => [
                ['key' => 'baseUrl', 'value' => 'http://localhost:8000/api/v1'],
            ],
            'item' => $items,
        ];

        File::put($output, json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $envPath = base_path('postman/Local.postman_environment.json');
        if (! File::exists($envPath)) {
            File::put($envPath, json_encode([
                'id' => 'pos-erp-local-env',
                'name' => 'POS ERP — Local',
                'values' => [
                    ['key' => 'baseUrl', 'value' => 'http://localhost:8000/api/v1', 'enabled' => true],
                    ['key' => 'token', 'value' => '', 'enabled' => true],
                    ['key' => 'id', 'value' => '1', 'enabled' => true],
                    ['key' => 'cartId', 'value' => '1', 'enabled' => true],
                    ['key' => 'saleId', 'value' => '1', 'enabled' => true],
                    ['key' => 'sessionId', 'value' => '1', 'enabled' => true],
                    ['key' => 'customerNum', 'value' => '1', 'enabled' => true],
                ],
                '_postman_variable_scope' => 'environment',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $this->info('Postman collection: ' . $output);
        $this->info('Environment: ' . $envPath);
        $count = 0;
        foreach ($items as $folder) {
            $count += count($folder['item'] ?? []);
        }
        $this->info('Requests: ' . $count);

        return self::SUCCESS;
    }

    protected function authFolder(array $requests): array
    {
        $items = [];
        foreach ($requests as $req) {
            if (str_contains($req['name'], 'POST auth/login')) {
                $req['event'] = [[
                    'listen' => 'test',
                    'script' => [
                        'exec' => [
                            'const json = pm.response.json();',
                            'if (json.token) {',
                            '    pm.environment.set("token", json.token);',
                            '    pm.collectionVariables.set("token", json.token);',
                            '    console.log("Sanctum token saved to environment");',
                            '}',
                        ],
                        'type' => 'text/javascript',
                    ],
                ]];
                $req['request']['auth'] = ['type' => 'noauth'];
                $req['request']['body'] = [
                    'mode' => 'raw',
                    'raw' => json_encode(['username' => 'admin', 'password' => 'password'], JSON_PRETTY_PRINT),
                    'options' => ['raw' => ['language' => 'json']],
                ];
            } else {
                $req['request']['auth'] = ['type' => 'bearer', 'bearer' => [
                    ['key' => 'token', 'value' => '{{token}}', 'type' => 'string'],
                ]];
            }
            $items[] = $req;
        }

        return ['name' => 'Auth', 'description' => 'Sanctum: login saves bearer token automatically.', 'item' => $items];
    }

    protected function buildRequestItem(Route $route, string $method): array
    {
        $uri = $route->uri();
        $path = Str::after($uri, 'api/v1/');
        $segments = explode('/', $path);
        $urlPath = [];
        $variables = [];

        foreach ($segments as $seg) {
            if (preg_match('/^\{(.+)\}$/', $seg, $m)) {
                $param = $m[1];
                $varName = $this->postmanVarForParam($param);
                $urlPath[] = '{{' . $varName . '}}';
                $variables[] = ['key' => $varName, 'value' => $this->defaultForParam($param)];
            } else {
                $urlPath[] = $seg;
            }
        }

        $request = [
            'method' => $method,
            'header' => [
                ['key' => 'Accept', 'value' => 'application/json'],
                ['key' => 'Content-Type', 'value' => 'application/json'],
            ],
            'url' => [
                'raw' => '{{baseUrl}}/' . implode('/', $urlPath),
                'host' => ['{{baseUrl}}'],
                'path' => $urlPath,
            ],
        ];

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && ! str_contains($uri, 'auth/login')) {
            $request['body'] = [
                'mode' => 'raw',
                'raw' => "{}",
                'options' => ['raw' => ['language' => 'json']],
            ];
        }

        if ($method === 'GET' && str_contains($uri, 'reports/') && ! str_contains($uri, '{')) {
            $request['url']['query'] = [
                ['key' => 'branch_id', 'value' => '1', 'disabled' => true],
                ['key' => 'per_page', 'value' => '50', 'disabled' => true],
            ];
            $request['url']['raw'] .= '?branch_id=1&per_page=50';
        }

        return [
            'name' => $method . ' ' . $path,
            'request' => $request,
        ];
    }

    protected function postmanVarForParam(string $param): string
    {
        return match ($param) {
            'cartId' => 'cartId',
            'saleId' => 'saleId',
            'sessionId' => 'sessionId',
            'customerNum' => 'customerNum',
            'runId' => 'id',
            'entryId' => 'id',
            default => 'id',
        };
    }

    protected function defaultForParam(string $param): string
    {
        return match ($param) {
            'customerNum' => '1',
            default => '1',
        };
    }

    protected function folderForRoute(Route $route): string
    {
        $uri = '/' . $route->uri();

        if (str_contains($uri, '/auth/')) {
            return 'Auth';
        }
        if (str_contains($uri, '/erp/')) {
            return 'Platform';
        }
        if (str_contains($uri, '/sales/carts') || str_contains($uri, '/sales/orders')) {
            return 'Sales Operations';
        }
        if (str_contains($uri, '/pos/')) {
            return 'POS Operations';
        }
        if (str_contains($uri, '/inventory/')) {
            return 'Inventory Operations';
        }
        if (str_contains($uri, '/reports')) {
            return 'Reports';
        }
        if (str_contains($uri, '/accounting/')) {
            return 'Accounting Operations';
        }
        if (str_contains($uri, '/payroll/runs')) {
            return 'HR Payroll Operations';
        }
        if (preg_match('#/sales/\{[^}]+\}/payments#', $uri)) {
            return 'Payments Operations';
        }

        $parts = explode('/', trim($uri, '/'));
        $segment = $parts[2] ?? 'General';

        return $this->resourceFolders[$segment] ?? Str::headline(str_replace('-', ' ', $segment));
    }
}
