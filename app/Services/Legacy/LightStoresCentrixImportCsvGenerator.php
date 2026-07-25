<?php

namespace App\Services\Legacy;

use ZipArchive;

/**
 * Convert LightStores MySQL dump SQL files into Centrix ERP import CSVs.
 *
 * Port of generate_centrix_import_csv.py for super-admin upload conversion.
 */
class LightStoresCentrixImportCsvGenerator
{
  private const KRA_PIN_PLACEHOLDERS = [
    '', 'K.R.A PIN', 'KRA PIN', 'KRA', 'PIN', 'S', 'A', 'N/A', 'NA', 'NULL',
  ];

  private SqlDumpInsertParser $parser;

  /** @param  array<string, string>  $sqlByTable */
  public function __construct(
    private array $sqlByTable = [],
    ?SqlDumpInsertParser $parser = null,
  ) {
    $this->parser = $parser ?? new SqlDumpInsertParser;
  }

  /** @param  array<int, \Illuminate\Http\UploadedFile>  $files */
  public static function fromUploadedFiles(array $files): self
  {
    $sqlByTable = [];
    $parser = new SqlDumpInsertParser;

    foreach ($files as $file) {
      if (! $file->isValid()) {
        continue;
      }
      $sql = (string) file_get_contents($file->getRealPath());
      $table = $parser->detectTableName($sql);
      if ($table === null) {
        $base = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $table = preg_replace('/^superdb_/', '', $base) ?: null;
      }
      if ($table === null) {
        continue;
      }
      $sqlByTable[$table] = ($sqlByTable[$table] ?? '')."\n".$sql;
    }

    return new self($sqlByTable, $parser);
  }

  /** @return array<string, string> filename => CSV content */
  public function generateAll(): array
  {
    $lookups = $this->loadLookupMaps();
    $routeNames = $lookups['routes'];

    [$supplierHeaders, $supplierRows, $supplierRefHeaders, $supplierRefRows] = $this->buildSuppliers();
    [$customerHeaders, $customerRows, $customerRefHeaders, $customerRefRows] = $this->buildCustomers($routeNames);
    [$productHeaders, $productRows] = $this->buildProducts($lookups);
    [$retailHeaders, $retailRows] = $this->buildRetailPackages();

    [$routesH, $routes] = $this->buildRoutesImport();
    [$catH, $cat] = $this->buildCategoriesImport();
    [$subH, $sub] = $this->buildSubcategoriesImport($lookups);
    [$uomH, $uom] = $this->buildUomsImport();
    [$vatH, $vat] = $this->buildVatsImport();

    [$refRoutesH, $refRoutes] = $this->buildReferenceRoutes();
    [$refCatH, $refCat] = $this->buildReferenceCategories();
    [$refSubH, $refSub] = $this->buildReferenceSubcategories();
    [$refUomH, $refUom] = $this->buildReferenceUoms();
    [$refVatH, $refVat] = $this->buildReferenceVats();

    return [
      'vats-import.csv' => $this->csvContent($vatH, $vat),
      'categories-import.csv' => $this->csvContent($catH, $cat),
      'subcategories-import.csv' => $this->csvContent($subH, $sub),
      'uoms-import.csv' => $this->csvContent($uomH, $uom),
      'routes-import.csv' => $this->csvContent($routesH, $routes),
      'suppliers-import.csv' => $this->csvContent($supplierHeaders, $supplierRows),
      'reference-suppliers.csv' => $this->csvContent($supplierRefHeaders, $supplierRefRows),
      'customers-import.csv' => $this->csvContent($customerHeaders, $customerRows),
      'reference-customers.csv' => $this->csvContent($customerRefHeaders, $customerRefRows),
      'products-import.csv' => $this->csvContent($productHeaders, $productRows),
      'retail-packages-import.csv' => $this->csvContent($retailHeaders, $retailRows),
      'reference-routes.csv' => $this->csvContent($refRoutesH, $refRoutes),
      'reference-categories.csv' => $this->csvContent($refCatH, $refCat),
      'reference-subcategories.csv' => $this->csvContent($refSubH, $refSub),
      'reference-uoms.csv' => $this->csvContent($refUomH, $refUom),
      'reference-vats.csv' => $this->csvContent($refVatH, $refVat),
      'README.md' => $this->buildReadme([
        'vats' => count($vat),
        'categories' => count($cat),
        'subcategories' => count($sub),
        'uoms' => count($uom),
        'routes' => count($routes),
        'suppliers' => count($supplierRows),
        'customers' => count($customerRows),
        'products' => count($productRows),
        'retail_packages' => count($retailRows),
      ]),
    ];
  }

  public function zipToTempFile(): string
  {
    $files = $this->generateAll();
    $tmp = tempnam(sys_get_temp_dir(), 'centrix-import-');
    $zipPath = $tmp.'.zip';
    @unlink($tmp);

    $zip = new ZipArchive;
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
      throw new \RuntimeException('Could not create ZIP archive.');
    }

    foreach ($files as $name => $content) {
      // Store uncompressed so Archive Utility / unzip always accept the archive.
      $zip->addFromString($name, $content);
      $zip->setCompressionName($name, ZipArchive::CM_STORE);
    }
    $zip->close();

    if (! is_file($zipPath) || filesize($zipPath) < 22) {
      throw new \RuntimeException('ZIP archive was not created correctly.');
    }

    return $zipPath;
  }

  /**
   * Import CSVs only (no reference/README) for direct per-file download bundles.
   *
   * @return array<string, string> filename => CSV content
   */
  public function generateImportCsvs(): array
  {
    $all = $this->generateAll();
    $importOnly = [];
    foreach ($all as $name => $content) {
      if (str_ends_with($name, '-import.csv')) {
        $importOnly[$name] = $content;
      }
    }

    return $importOnly;
  }

  /** @return list<list<mixed>> */
  private function loadRows(string $table): array
  {
    $sql = $this->sqlByTable[$table] ?? '';
    if ($sql === '') {
      return [];
    }

    return $this->parser->loadRows($sql, $table);
  }

  /** @return array<string, mixed> */
  private function loadLookupMaps(): array
  {
    $categories = [];
    foreach ($this->loadRows('category') as $r) {
      if (count($r) >= 4 && $r[2] !== null) {
        $categories[(int) $r[2]] = $this->cleanText($r[3]);
      }
    }

    $subcategories = [];
    foreach ($this->loadRows('sub_category') as $r) {
      if (count($r) < 6 || $r[2] === null) {
        continue;
      }
      $subId = (int) $r[2];
      $catId = ($r[5] ?? null) !== null && $r[5] !== '' ? (int) $r[5] : 0;
      $subcategories[$subId] = [$this->cleanText($r[3]), $categories[$catId] ?? ''];
    }

    $uoms = [];
    foreach ($this->loadRows('uom') as $r) {
      if (count($r) >= 5 && $r[2] !== null) {
        $uoms[(int) $r[2]] = $this->legacyUomMeasureName($r);
      }
    }

    $vats = [];
    foreach ($this->loadRows('vat_status') as $r) {
      if (count($r) < 6 || $r[2] === null) {
        continue;
      }
      $code = $this->cleanText($r[4]) ?: 'VAT'.(int) $r[2];
      $name = $this->cleanText($r[3]) ?: $code;
      if (strtolower($name) === 'vatable') {
        $pct = $r[5];
        $name = $pct !== null && $pct !== '' ? "VAT {$pct}%" : $name;
      }
      $vats[(int) $r[2]] = $code;
    }

    $routes = [];
    foreach ($this->loadRows('routes') as $r) {
      if ($r && $r[0] !== null) {
        $routes[(int) $r[0]] = $this->cleanText($r[1]);
      }
    }

    $suppliers = [];
    foreach ($this->loadRows('suppliers') as $r) {
      if (count($r) < 12 || $r[0] === null || $r[11] !== null) {
        continue;
      }
      $name = $this->cleanText($r[1]);
      if ($name !== '') {
        $suppliers[(int) $r[0]] = $name;
      }
    }

    return compact('categories', 'subcategories', 'uoms', 'vats', 'routes', 'suppliers');
  }

  /** @return array{0: list<string>, 1: list<list<string>>} */
  private function buildRetailPackages(): array
  {
    $headers = [
      'product_code', 'max_qty_measure', 'markup_price', 'min_uom_measure',
      'max_uom_measure', 'wholesale_qty_measure', 'wholesale_markup_price',
    ];
    $rows = [];
    $seen = [];

    foreach ($this->loadRows('retail_package_setting') as $r) {
      if (count($r) < 9) {
        continue;
      }
      $productCode = trim((string) $r[1]);
      if ($productCode === '' || isset($seen[$productCode])) {
        continue;
      }
      $seen[$productCode] = true;

      $maxQty = (float) ($r[2] ?? 0);
      $markup = (float) ($r[3] ?? 0);
      $wholesaleQty = (float) ($r[7] ?? 0);
      if ($maxQty == 0.0 && $markup == 0.0 && $wholesaleQty == 0.0) {
        continue;
      }

      $rows[] = $this->csvEscapeRow([
        $productCode,
        $maxQty,
        $markup,
        $this->cleanText($r[4]),
        $this->cleanText($r[5]),
        $wholesaleQty,
        (float) ($r[8] ?? 0),
      ]);
    }

    return [$headers, $rows];
  }

  /** @param  array<string, mixed>  $lookups
   * @return array{0: list<string>, 1: list<list<string>>}
   */
  private function buildProducts(array $lookups): array
  {
    // Matches Centrix advanced product import sample headers (incl. optional shelf_location).
    $headers = [
      'product_code', 'product_name', 'category_name', 'subcategory_name', 'measure_name',
      'unit_price', 'last_cost_price', 'discount_type', 'discount_percentage', 'discount_value',
      'product_weight', 'shelf_location', 'stock_in_shop', 'stock_in_store', 'reorder_point',
      'supplier_name', 'vat_code', 'sell_on_retail',
    ];
    $rows = [];
    $seen = [];
    $subcategories = $lookups['subcategories'];
    $uoms = $lookups['uoms'];
    $vats = $lookups['vats'];
    $suppliers = $lookups['suppliers'];

    foreach ($this->loadRows('product') as $r) {
      if (count($r) < 29 || $r[26] !== null) {
        continue;
      }
      $productCode = $this->cleanText($r[4]);
      $productName = $this->cleanText($r[5]);
      if ($productCode === '' || $productName === '' || isset($seen[$productCode])) {
        continue;
      }
      $seen[$productCode] = true;

      $subcategoryId = $r[7];
      $unitId = $r[8];
      if (in_array($subcategoryId, [null, 0, '0'], true) || in_array($unitId, [null, 0, '0'], true)) {
        continue;
      }

      $subcategoryId = (int) $subcategoryId;
      $unitId = (int) $unitId;
      [$subName, $catName] = $subcategories[$subcategoryId] ?? ['', ''];
      $subName = $this->normalizeSubcategoryName($subName);
      $measureName = $uoms[$unitId] ?? '';
      if ($measureName === '') {
        $measureName = $this->inferMeasureName($productName, $subName);
      }

      $supplierName = '';
      $legacySupplierId = $r[12];
      if (! in_array($legacySupplierId, [null, 0, '0'], true)) {
        $supplierName = $suppliers[(int) $legacySupplierId] ?? '';
      }

      $vatCode = '';
      if (! in_array($r[24] ?? null, [null, 0, '0'], true)) {
        $vatCode = $vats[(int) $r[24]] ?? '';
      }

      $discountType = $this->cleanText($r[16]) ?: 'percentage';
      $discountAmount = (float) ($r[17] ?? 0);
      $discountPercentage = $discountType === 'percentage' ? $discountAmount : '';
      $discountValue = $discountType === 'fixed' ? $discountAmount : '';

      $rows[] = $this->csvEscapeRow([
        $productCode,
        $productName,
        $catName,
        $subName,
        $measureName,
        (float) ($r[11] ?? 0),
        (float) ($r[13] ?? 0),
        $discountType,
        $discountPercentage,
        $discountValue,
        $r[15] !== null ? (float) $r[15] : '',
        '', // shelf_location — not present in LightStores dumps
        (float) ($r[9] ?? 0),
        (float) ($r[10] ?? 0),
        0,
        $supplierName,
        $vatCode,
        in_array($r[28] ?? null, [1, '1', true], true) ? 'true' : 'false',
      ]);
    }

    return [$headers, $rows];
  }

  /** @return array{0: list<string>, 1: list<list<string>>, 2: list<string>, 3: list<list<string>>} */
  private function buildSuppliers(): array
  {
    // Matches Centrix supplier advanced-import sample headers exactly.
    $headers = [
      'supplier_name', 'supplier_code', 'contact_person', 'phone', 'alternate_phone',
      'email', 'town', 'tax_pin', 'terms_of_payment', 'address', 'is_active',
    ];
    $referenceHeaders = array_merge(['legacy_supplier_id'], $headers);
    $rows = [];
    $referenceRows = [];
    $seenNames = [];

    foreach ($this->loadRows('suppliers') as $r) {
      // LightStores `suppliers`:
      // 0 SPLR_ID, 1 SPLR_NAME, 2 SPLR_EMAIL, 3 SPLR_CNTCT, 4 CNTCT_PRSN,
      // 5 SPLR_ADDRS, 6 SPLR_TOWN, 7 ACTV_FLG, 8 DLT_BY, 9 DLT_ON, … 12 ADDNL_INFO
      if (count($r) < 8) {
        continue;
      }
      if (($r[9] ?? null) !== null) {
        continue;
      }
      if (! in_array($r[7] ?? null, [1, '1', true], true)) {
        continue;
      }
      $name = $this->cleanText($r[1]);
      if ($name === '') {
        continue;
      }
      $key = strtoupper($name);
      if (isset($seenNames[$key])) {
        continue;
      }
      $seenNames[$key] = true;

      $legacyId = (int) ($r[0] ?? 0);
      $row = $this->csvEscapeRow([
        $name,
        $legacyId > 0 ? 'LS-'.$legacyId : '',
        $this->cleanText($r[4]),
        $this->cleanText($r[3]),
        '',
        $this->cleanText($r[2]),
        $this->cleanText($r[6]),
        '', // tax_pin — not on LightStores suppliers
        $this->cleanText($r[12] ?? ''), // ADDNL_INFO → terms_of_payment
        $this->cleanText($r[5]), // SPLR_ADDRS
        'true',
      ]);
      $rows[] = $row;
      $referenceRows[] = $this->csvEscapeRow(array_merge([(string) $r[0]], $row));
    }

    return [$headers, $rows, $referenceHeaders, $referenceRows];
  }

  /** @param  array<int, string>  $routeNames
   * @return array{0: list<string>, 1: list<list<string>>, 2: list<string>, 3: list<list<string>>}
   */
  private function buildCustomers(array $routeNames): array
  {
    // Matches Centrix customer advanced-import sample headers exactly.
    $headers = [
      'customer_name', 'customer_type', 'phone_number', 'additional_phone', 'town',
      'route_name', 'branch_id', 'kra_pin', 'terms_of_payment', 'credit_limit',
      'latitude', 'longitude',
    ];
    $referenceHeaders = [
      'legacy_customer_num', 'customer_name', 'customer_type', 'legacy_phone_raw',
      'import_phone', 'legacy_additional_phone_raw', 'import_additional_phone',
      'legacy_route_id', 'route_name', 'import_notes',
    ];
    $rows = [];
    $referenceRows = [];
    $seenNums = [];
    $seenPhones = [];

    foreach ($this->loadRows('customer') as $r) {
      $mapped = $this->mapLegacyCustomerColumns($r);
      if ($mapped === null) {
        continue;
      }

      $num = (int) $mapped['customer_num'];
      if ($num <= 0 || isset($seenNums[$num])) {
        continue;
      }
      $seenNums[$num] = true;

      $name = $this->cleanText($mapped['name']);
      if ($name === '') {
        continue;
      }

      $routeId = $mapped['route_id'];
      $routeId = ! in_array($routeId, [null, 0, '0'], true) ? (int) $routeId : null;
      if ($routeId !== null && ! isset($routeNames[$routeId])) {
        $routeId = null;
      }

      $custStatus = (string) ($mapped['cust_status'] ?? '0');
      $customerType = $custStatus === '1' ? 'debtor' : 'route';
      $routeName = '';
      if ($customerType === 'route' && $routeId !== null) {
        $routeName = $routeNames[$routeId] ?? '';
      }
      if ($customerType === 'debtor') {
        $routeId = null;
      }

      $rawPhone = $this->cleanText($mapped['phone']);
      $rawAdditional = $this->cleanText($mapped['additional']);
      $phone = $this->normalizePhone($rawPhone);
      $additionalPhone = $this->normalizePhone($rawAdditional);
      $notes = [];

      if ($rawPhone !== '' && $phone === '') {
        $notes[] = 'invalid primary phone cleared';
      }
      if ($phone !== '') {
        if (isset($seenPhones[$phone])) {
          $notes[] = "duplicate primary phone cleared ({$phone})";
          $phone = '';
        } else {
          $seenPhones[$phone] = true;
        }
      }
      if ($additionalPhone !== '') {
        if (isset($seenPhones[$additionalPhone])) {
          $notes[] = "duplicate additional phone cleared ({$additionalPhone})";
          $additionalPhone = '';
        } else {
          $seenPhones[$additionalPhone] = true;
        }
      }

      $kraPin = $this->cleanKraPin($mapped['kra_pin']);

      $rows[] = $this->csvEscapeRow([
        $name, $customerType, $phone, $additionalPhone, $this->cleanText($mapped['town']),
        $routeName, '', $kraPin, $this->cleanText($mapped['terms']), $this->formatCreditLimit($mapped['credit_limit']),
        $this->formatCoordinate($mapped['lat']), $this->formatCoordinate($mapped['lng']),
      ]);

      if ($notes !== [] || $num > 0) {
        $referenceRows[] = $this->csvEscapeRow([
          (string) $num, $name, $customerType, $rawPhone, $phone, $rawAdditional,
          $additionalPhone, $routeId !== null ? (string) $routeId : '', $routeName,
          implode('; ', $notes),
        ]);
      }
    }

    return [$headers, $rows, $referenceHeaders, $referenceRows];
  }

  /**
   * Normalize LightStores customer dump row indices.
   * Classic schema (~15 cols) and extended dumps (20+ cols with lat/lng/credit) are both supported.
   *
   * @param  list<mixed>  $r
   * @return array{customer_num: mixed, name: mixed, phone: mixed, additional: mixed, town: mixed, route_id: mixed, cust_status: mixed, kra_pin: mixed, terms: mixed, credit_limit: mixed, lat: mixed, lng: mixed}|null
   */
  private function mapLegacyCustomerColumns(array $r): ?array
  {
    // Extended dump used by older converter (lat/lng/credit inserted before route/status).
    if (count($r) >= 20) {
      if ($r[19] !== null) {
        return null;
      }

      return [
        'customer_num' => $r[2],
        'name' => $r[3],
        'phone' => $r[4],
        'additional' => $r[5],
        'town' => $r[6],
        'kra_pin' => $r[7],
        'terms' => $r[8],
        'lat' => $r[9],
        'lng' => $r[10],
        'credit_limit' => $r[12],
        'route_id' => $r[14],
        'cust_status' => $r[17] ?? 0,
      ];
    }

    // Classic LightStores `customer` table:
    // 0 create_time, 1 update_time, 2 customer_num, 3 customer_name, 4 phone_number,
    // 5 addnl_phone_number, 6 town, 7 route_id, 8 user_id, 9 org_id, 10 cust_status,
    // 11 kra_pin, 12 terms_of_payment, 13 dlt_by, 14 dlt_on
    if (count($r) < 11) {
      return null;
    }
    if (count($r) >= 15 && ($r[14] ?? null) !== null) {
      return null;
    }

    return [
      'customer_num' => $r[2],
      'name' => $r[3],
      'phone' => $r[4],
      'additional' => $r[5],
      'town' => $r[6],
      'route_id' => $r[7],
      'cust_status' => $r[10] ?? 0,
      'kra_pin' => $r[11] ?? '',
      'terms' => $r[12] ?? '',
      'credit_limit' => '',
      'lat' => '',
      'lng' => '',
    ];
  }

  /** @return array{0: list<string>, 1: list<list<string>>} */
  private function buildRoutesImport(): array
  {
    $headers = ['route_name', 'direction', 'route_markup_price', 'is_active'];
    $rows = [];
    foreach ($this->loadRows('routes') as $r) {
      if (count($r) < 3) {
        continue;
      }
      $rows[] = $this->csvEscapeRow([
        $this->cleanText($r[1]), '', (int) ($r[2] ?? 0), 'true',
      ]);
    }

    return [$headers, $rows];
  }

  /** @return array{0: list<string>, 1: list<list<string>>} */
  private function buildCategoriesImport(): array
  {
    $headers = ['category_name'];
    $rows = [];
    foreach ($this->loadRows('category') as $r) {
      if (count($r) < 4) {
        continue;
      }
      $name = $this->cleanText($r[3]);
      if ($name !== '') {
        $rows[] = $this->csvEscapeRow([$name]);
      }
    }

    return [$headers, $rows];
  }

  /** @param  array<string, mixed>  $lookups
   * @return array{0: list<string>, 1: list<list<string>>}
   */
  private function buildSubcategoriesImport(array $lookups): array
  {
    $headers = ['category_name', 'subcategory_name'];
    $rows = [];
    $categories = $lookups['categories'];
    foreach ($this->loadRows('sub_category') as $r) {
      if (count($r) < 6) {
        continue;
      }
      $subName = $this->normalizeSubcategoryName($this->cleanText($r[3]));
      $catId = ($r[5] ?? null) !== null && $r[5] !== '' ? (int) $r[5] : 0;
      $catName = $categories[$catId] ?? '';
      if ($subName !== '' && $catName !== '') {
        $rows[] = $this->csvEscapeRow([$catName, $subName]);
      }
    }

    return [$headers, $rows];
  }

  /** @return array{0: list<string>, 1: list<list<string>>} */
  private function buildUomsImport(): array
  {
    // Matches Centrix UOM advanced-import sample headers exactly.
    $headers = [
      'measure_name', 'full_name', 'uses_small_packaging', 'small_packaging_label',
      'middle_packaging_label', 'middle_factor', 'conversion_factor', 'uom_type', 'is_active',
    ];
    $usedUnitIds = $this->activeProductUnitIds();
    $byMeasure = [];

    foreach ($this->loadRows('uom') as $r) {
      if (count($r) < 8) {
        continue;
      }
      $legacyId = (int) $r[2];
      $deleted = $r[7] !== null;
      $isActive = in_array($legacyId, $usedUnitIds, true) || ! $deleted;
      $measureName = $this->legacyUomMeasureName($r);
      if ($measureName === '') {
        continue;
      }

      $fullName = $this->cleanText($r[4]) ?: $measureName;
      $uomType = $this->cleanText($r[5]) ?: 'piece';
      $factor = $this->legacyUomConversionFactor($r);
      $rowData = [
        'measure_name' => $measureName,
        'full_name' => $fullName,
        'factor' => fmod($factor, 1.0) === 0.0 ? (int) $factor : $factor,
        'uom_type' => $uomType,
        'is_active' => $isActive,
        'legacy_id' => $legacyId,
      ];

      $existing = $byMeasure[$measureName] ?? null;
      if ($existing === null) {
        $byMeasure[$measureName] = $rowData;

        continue;
      }

      $existingScore = [($existing['is_active'] ? 2 : 0), -$existing['legacy_id']];
      $candidateScore = [($isActive ? 2 : 0), -$legacyId];
      $preferCandidate = $candidateScore[0] > $existingScore[0]
        || ($candidateScore[0] === $existingScore[0] && $candidateScore[1] > $existingScore[1]);
      if ($preferCandidate) {
        $byMeasure[$measureName] = $rowData;
      } elseif ($isActive) {
        $byMeasure[$measureName]['is_active'] = true;
      }
    }

    uasort($byMeasure, fn (array $a, array $b) => $a['legacy_id'] <=> $b['legacy_id']);
    $rows = [];
    foreach ($byMeasure as $data) {
      $rows[] = $this->csvEscapeRow([
        $data['measure_name'], $data['full_name'], 'true', 'piece', '', '',
        $data['factor'], $data['uom_type'], $data['is_active'] ? 'true' : 'false',
      ]);
    }

    return [$headers, $rows];
  }

  /** @return array{0: list<string>, 1: list<list<string>>} */
  private function buildVatsImport(): array
  {
    $headers = ['vat_code', 'vat_name', 'vat_percentage', 'is_active'];
    $rows = [];
    foreach ($this->loadRows('vat_status') as $r) {
      if (count($r) < 6) {
        continue;
      }
      $legacyId = (int) $r[2];
      $code = $this->cleanText($r[4]) ?: "VAT{$legacyId}";
      $name = $this->cleanText($r[3]) ?: $code;
      if (strtolower($name) === 'vatable') {
        $pct = $r[5];
        $name = $pct !== null && $pct !== '' ? "VAT {$pct}%" : $name;
      }
      $rows[] = $this->csvEscapeRow([$code, $name, (float) ($r[5] ?? 0), 'true']);
    }

    return [$headers, $rows];
  }

  /** @return array{0: list<string>, 1: list<list<string>>} */
  private function buildReferenceRoutes(): array
  {
    $headers = ['legacy_route_id', 'route_name', 'route_markup_price'];
    $rows = [];
    foreach ($this->loadRows('routes') as $r) {
      if (count($r) < 3) {
        continue;
      }
      $rows[] = $this->csvEscapeRow([(string) $r[0], $this->cleanText($r[1]), (string) ($r[2] ?? 0)]);
    }

    return [$headers, $rows];
  }

  /** @return array{0: list<string>, 1: list<list<string>>} */
  private function buildReferenceCategories(): array
  {
    $headers = ['legacy_category_id', 'category_name'];
    $rows = [];
    foreach ($this->loadRows('category') as $r) {
      if (count($r) < 4) {
        continue;
      }
      $rows[] = $this->csvEscapeRow([(string) $r[2], $this->cleanText($r[3])]);
    }

    return [$headers, $rows];
  }

  /** @return array{0: list<string>, 1: list<list<string>>} */
  private function buildReferenceSubcategories(): array
  {
    $headers = ['legacy_subcategory_id', 'subcategory_name', 'legacy_category_id', 'category_name'];
    $rows = [];
    $lookups = $this->loadLookupMaps();
    $categories = $lookups['categories'];
    foreach ($this->loadRows('sub_category') as $r) {
      if (count($r) < 6) {
        continue;
      }
      $catId = ($r[5] ?? null) !== null && $r[5] !== '' ? (int) $r[5] : 0;
      $rows[] = $this->csvEscapeRow([
        (string) $r[2], $this->cleanText($r[3]), (string) $catId, $categories[$catId] ?? '',
      ]);
    }

    return [$headers, $rows];
  }

  /** @return array{0: list<string>, 1: list<list<string>>} */
  private function buildReferenceUoms(): array
  {
    $headers = ['legacy_uom_id', 'measure_name', 'full_name', 'conversion_factor', 'uom_type'];
    $rows = [];
    foreach ($this->loadRows('uom') as $r) {
      if (count($r) < 6) {
        continue;
      }
      $rows[] = $this->csvEscapeRow([
        (string) $r[2], $this->legacyUomMeasureName($r), $this->cleanText($r[4]),
        (string) $this->legacyUomConversionFactor($r), $this->cleanText($r[5]),
      ]);
    }

    return [$headers, $rows];
  }

  /** @return array{0: list<string>, 1: list<list<string>>} */
  private function buildReferenceVats(): array
  {
    $headers = ['legacy_vat_id', 'vat_name', 'vat_code', 'vat_percentage'];
    $rows = [];
    foreach ($this->loadRows('vat_status') as $r) {
      if (count($r) < 6) {
        continue;
      }
      $rows[] = $this->csvEscapeRow([(string) $r[2], $this->cleanText($r[3]), $this->cleanText($r[4]), (string) ($r[5] ?? 0)]);
    }

    return [$headers, $rows];
  }

  /** @return list<int> */
  private function activeProductUnitIds(): array
  {
    $ids = [];
    foreach ($this->loadRows('product') as $r) {
      if (count($r) < 29 || $r[26] !== null) {
        continue;
      }
      $unitId = $r[8];
      if (! in_array($unitId, [null, 0, '0'], true)) {
        $ids[] = (int) $unitId;
      }
    }

    return $ids;
  }

  /** @param  array<string, int>  $counts */
  private function buildReadme(array $counts): string
  {
    return <<<MD
# Centrix ERP — manual import files (from LightStores dump)

## File counts

- VAT rates: {$counts['vats']}
- Categories: {$counts['categories']}
- Subcategories: {$counts['subcategories']}
- Units of measure: {$counts['uoms']}
- Routes: {$counts['routes']}
- Suppliers: {$counts['suppliers']}
- Customers: {$counts['customers']}
- Products: {$counts['products']}
- Retail packages: {$counts['retail_packages']}

## Recommended import order

1. `vats-import.csv`
2. `categories-import.csv`
3. `subcategories-import.csv`
4. `uoms-import.csv`
5. `routes-import.csv`
6. `suppliers-import.csv`
7. `customers-import.csv`
8. `products-import.csv`
9. `retail-packages-import.csv`

Reference `reference-*.csv` files are for audit only — do not import them directly.
MD;
  }

  /** @param  list<string>  $headers
   * @param  list<list<string>>  $rows
   */
  private function csvContent(array $headers, array $rows): string
  {
    $fp = fopen('php://temp', 'r+');
    if ($fp === false) {
      return '';
    }
    fputcsv($fp, $headers);
    foreach ($rows as $row) {
      fputcsv($fp, $row);
    }
    rewind($fp);
    $content = stream_get_contents($fp);
    fclose($fp);

    return $content !== false ? $content : '';
  }

  /** @param  list<mixed>  $values
   * @return list<string>
   */
  private function csvEscapeRow(array $values): array
  {
    return array_map(function ($v) {
      if ($v === null) {
        return '';
      }
      if (is_bool($v)) {
        return $v ? 'true' : 'false';
      }

      return (string) $v;
    }, $values);
  }

  private function cleanText(mixed $value): string
  {
    if ($value === null) {
      return '';
    }
    $text = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    if (in_array(strtoupper($text), ['NULL', 'N/A', 'N/A.', 'NA'], true)) {
      return '';
    }

    return $text;
  }

  private function normalizePhone(mixed $value): string
  {
    $text = $this->cleanText($value);
    if ($text === '') {
      return '';
    }
    $digits = preg_replace('/\D+/', '', $text) ?? '';
    if ($digits === '') {
      return '';
    }
    if (str_starts_with($digits, '254') && strlen($digits) >= 12) {
      $digits = '0'.substr($digits, 3);
    }
    if (strlen($digits) === 9 && $digits[0] === '7') {
      $digits = '0'.$digits;
    }
    if (strlen($digits) < 9) {
      return '';
    }

    return $digits;
  }

  private function cleanKraPin(mixed $value): string
  {
    $pin = strtoupper($this->cleanText($value));
    if (in_array($pin, self::KRA_PIN_PLACEHOLDERS, true) || strlen($pin) <= 2) {
      return '';
    }

    return $pin;
  }

  private function formatCreditLimit(mixed $value): string
  {
    if ($value === null || $value === '') {
      return '0';
    }
    $amount = (float) $value;

    return fmod($amount, 1.0) === 0.0 ? (string) (int) $amount : (string) $amount;
  }

  private function formatCoordinate(mixed $value): string
  {
    if ($value === null || $value === '') {
      return '';
    }
    $number = (float) $value;
    if ($number == 0.0) {
      return '';
    }
    $formatted = rtrim(rtrim(number_format($number, 7, '.', ''), '0'), '.');

    return $formatted;
  }

  /** @param  list<mixed>  $row */
  private function legacyUomMeasureName(array $row): string
  {
    $shortName = $row[3] ?? null;
    $fullName = $this->cleanText($row[4] ?? '');
    if (is_int($shortName) || is_float($shortName)) {
      return $fullName;
    }
    $shortText = $this->cleanText($shortName);

    return $shortText !== '' ? $shortText : $fullName;
  }

  /** @param  list<mixed>  $row */
  private function legacyUomConversionFactor(array $row): float
  {
    $shortName = $row[3] ?? null;
    if (is_int($shortName) || is_float($shortName)) {
      $factor = (float) $shortName;

      return $factor > 0 ? $factor : 1.0;
    }
    if (is_string($shortName) && preg_match('/^[\d.]+$/', trim($shortName))) {
      $factor = (float) trim($shortName);

      return $factor > 0 ? $factor : 1.0;
    }

    return 1.0;
  }

  private function normalizeSubcategoryName(string $name): string
  {
    $cleaned = $this->cleanText($name);
    if (strtoupper($cleaned) === 'WINES AND SPIRIS') {
      return 'WINES AND SPRITS';
    }

    return $cleaned;
  }

  private function inferMeasureName(string $productName, string $subcategoryName): string
  {
    $upper = strtoupper($productName);
    if (preg_match('/10\s*L/', $upper)) {
      return '1 x 10L';
    }
    if (preg_match('/20\s*L/', $upper)) {
      return '1 x 20L';
    }
    if (preg_match('/(?<![0-9])1\s*L\b/', $upper) || preg_match('/\b1L\b/', $upper)) {
      return '1 x1pc';
    }
    if ($subcategoryName === 'SOFT DRINKS') {
      return 'CARTON';
    }
    if ($subcategoryName === 'FATS AND OILS') {
      return '1 x 20L';
    }

    return '1 x1pc';
  }
}
