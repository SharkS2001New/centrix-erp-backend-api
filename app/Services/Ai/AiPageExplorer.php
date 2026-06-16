<?php

namespace App\Services\Ai;

use App\Models\User;
use Illuminate\Support\Facades\Route;

class AiPageExplorer
{
    public function __construct(
        protected AiEntitySchemaCatalog $schemas,
    ) {}

    /** @return array<string, mixed> */
    public function analyze(User $user, string $path): array
    {
        $path = '/'.trim($path, '/');
        if ($path === '/') {
            $path = '/dashboard';
        }

        $navMatch = $this->findNavItem($path);
        $entities = $this->entitiesForPath($path);
        $entityDetails = [];
        foreach ($entities as $entityKey) {
            $detail = $this->schemas->forEntityWithOptions($user, $entityKey);
            if ($detail) {
                $entityDetails[$entityKey] = $detail;
            }
        }

        $apiRoutes = $this->relatedApiRoutes($path);
        $summary = $this->buildSummary($path, $navMatch, $entities, $entityDetails, $apiRoutes);

        return [
            'path' => $path,
            'navigation' => $navMatch,
            'entities' => array_keys($entityDetails),
            'entity_schemas' => $entityDetails,
            'api_routes' => $apiRoutes,
            'summary' => $summary,
            'topic' => $navMatch['label'] ?? ($entities[0] ?? 'ERP module'),
        ];
    }

    /** @return array<string, mixed>|null */
    protected function findNavItem(string $path): ?array
    {
        foreach (config('ai_navigation.sections', []) as $section) {
            foreach ($section['items'] ?? [] as $item) {
                if (($item['path'] ?? '') === $path) {
                    return [
                        'section' => $section['label'] ?? '',
                        'label' => $item['label'] ?? '',
                        'path' => $item['path'],
                        'module' => $item['module'] ?? null,
                        'permission' => $item['permission'] ?? null,
                    ];
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    protected function entitiesForPath(string $path): array
    {
        $matches = [];
        foreach (config('ai_entity_schemas', []) as $entity => $schema) {
            if (($schema['path'] ?? '') === $path) {
                $matches[] = $entity;
            }
        }

        return $matches;
    }

    /** @return list<string> */
    protected function relatedApiRoutes(string $path): array
    {
        $segment = trim(str_replace('/', ' ', $path));
        $keywords = array_filter(explode(' ', str_replace(['/', '-'], ' ', $segment)));
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }
            foreach ($keywords as $keyword) {
                if (strlen($keyword) >= 3 && str_contains($uri, $keyword)) {
                    $routes[] = strtoupper($route->methods()[0] ?? 'GET').' /'.$uri;
                    break;
                }
            }
        }

        return array_values(array_unique(array_slice($routes, 0, 12)));
    }

    /** @param  list<string>  $entities
     * @param  array<string, mixed>  $entityDetails
     * @param  list<string>  $apiRoutes
     */
    protected function buildSummary(
        string $path,
        ?array $navMatch,
        array $entities,
        array $entityDetails,
        array $apiRoutes,
    ): string {
        $lines = ['Page: '.$path];

        if ($navMatch) {
            $lines[] = 'Navigation: '.($navMatch['section'] ?? '').' › '.($navMatch['label'] ?? '');
            if (! empty($navMatch['module'])) {
                $lines[] = 'Module key: '.$navMatch['module'];
            }
        }

        foreach ($entityDetails as $entityKey => $schema) {
            $lines[] = '';
            $lines[] = ($schema['label'] ?? $entityKey).' fields:';
            foreach ($schema['fields'] ?? [] as $name => $field) {
                $flags = [];
                if (! empty($field['required'])) {
                    $flags[] = 'required';
                }
                if (! empty($field['auto_generated'])) {
                    $flags[] = 'auto-generated';
                }
                if (! empty($field['important'])) {
                    $flags[] = 'important';
                }
                if (! empty($field['relation']['table'])) {
                    $flags[] = 'FK → '.$field['relation']['table'];
                }
                $flagText = $flags !== [] ? ' ('.implode(', ', $flags).')' : '';
                $lines[] = "- {$name}: ".($field['label'] ?? $name).$flagText;
            }
        }

        if ($apiRoutes !== []) {
            $lines[] = '';
            $lines[] = 'Related API routes:';
            foreach ($apiRoutes as $route) {
                $lines[] = '- '.$route;
            }
        }

        return implode("\n", $lines);
    }
}
