<?php

namespace App\Services\Background;

use InvalidArgumentException;

/**
 * Resolves optional row mappers for list API exports (source: api).
 */
class ListExportMapperResolver
{
    /** @var array<string, class-string<ListExportRowMapper>> */
    private const MAP = [
        '/employees' => EmployeeListExportMapper::class,
        '/employee-attendance' => EmployeeAttendanceListExportMapper::class,
        '/employee-leave-days' => EmployeeLeaveDayListExportMapper::class,
        '/products' => ProductCatalogExportMapper::class,
        '/customers' => CustomerCatalogExportMapper::class,
        '/suppliers' => SupplierCatalogExportMapper::class,
    ];

    public function resolve(string $path): ?ListExportRowMapper
    {
        $normalized = '/'.ltrim($path, '/');

        foreach (self::MAP as $prefix => $class) {
            if ($normalized === $prefix || str_starts_with($normalized, $prefix.'/')) {
                return app($class);
            }
        }

        return null;
    }

    public function assertAllowedPath(string $path): string
    {
        $normalized = '/'.ltrim($path, '/');
        if ($normalized === '') {
            throw new InvalidArgumentException('API path is required for list export.');
        }

        return $normalized;
    }
}

interface ListExportRowMapper
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function mapBatch(array $rows): array;
}
