<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\PlatformInvoice;
use App\Models\PlatformInvoiceSavedTemplate;
use App\Services\Platform\PlatformInvoiceBillingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformInvoiceController extends Controller
{
    public function __construct(
        protected PlatformInvoiceBillingService $billing,
    ) {}

    public function index()
    {
        $invoices = PlatformInvoice::query()
            ->with('organization:id,company_code,org_name')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $invoices->map(fn (PlatformInvoice $inv) => $this->invoicePayload($inv))]);
    }

    public function show(int $invoice)
    {
        $model = PlatformInvoice::query()->with('organization:id,company_code,org_name')->findOrFail($invoice);

        return response()->json(['data' => $this->invoicePayload($model)]);
    }

    public function store(Request $request)
    {
        $data = $this->validateInvoice($request);
        $totals = $this->billing->calculateTotals($data['line_items'], (float) $data['tax_rate']);

        $invoice = PlatformInvoice::query()->create([
            ...$data,
            'invoice_number' => $data['invoice_number'] ?? $this->billing->nextInvoiceNumber(),
            'seller' => $data['seller'] ?? $this->billing->platformSeller(),
            'subtotal' => $totals['subtotal'],
            'tax_amount' => $totals['tax_amount'],
            'total' => $totals['total'],
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'data' => $this->invoicePayload($invoice->fresh('organization:id,company_code,org_name')),
            'message' => 'Invoice created.',
        ], 201);
    }

    public function update(Request $request, int $invoice)
    {
        $model = PlatformInvoice::query()->findOrFail($invoice);
        $data = $this->validateInvoice($request, $model);
        $totals = $this->billing->calculateTotals($data['line_items'], (float) $data['tax_rate']);

        $model->fill([
            ...$data,
            'subtotal' => $totals['subtotal'],
            'tax_amount' => $totals['tax_amount'],
            'total' => $totals['total'],
        ]);
        $model->save();

        return response()->json([
            'data' => $this->invoicePayload($model->fresh('organization:id,company_code,org_name')),
            'message' => 'Invoice updated.',
        ]);
    }

    public function destroy(int $invoice)
    {
        $model = PlatformInvoice::query()->findOrFail($invoice);
        $model->delete();

        return response()->json(['message' => 'Invoice deleted.']);
    }

    public function billingContext(Request $request)
    {
        $data = $request->validate([
            'organization_id' => 'nullable|integer|exists:organizations,id',
        ]);

        $org = null;
        if (! empty($data['organization_id'])) {
            $org = $this->findTenantOrganization((int) $data['organization_id']);
        }

        return response()->json($this->billing->billingContext($org));
    }

    public function designTemplates()
    {
        return response()->json([
            'data' => $this->billing->builtInDesignTemplates(),
        ]);
    }

    public function listSavedTemplates()
    {
        $templates = PlatformInvoiceSavedTemplate::query()->orderBy('name')->get();

        return response()->json(['data' => $templates]);
    }

    public function storeSavedTemplate(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120|unique:platform_invoice_saved_templates,name',
            'description' => 'nullable|string|max:400',
            'template_id' => 'required|string|max:40',
            'invoice_options' => 'nullable|array',
            'line_items' => 'nullable|array',
            'selected_modules' => 'nullable|array',
            'notes' => 'nullable|string|max:5000',
            'terms' => 'nullable|string|max:5000',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $template = PlatformInvoiceSavedTemplate::query()->create([
            ...$data,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'data' => $template,
            'message' => 'Invoice template saved.',
        ], 201);
    }

    public function destroySavedTemplate(int $template)
    {
        PlatformInvoiceSavedTemplate::query()->whereKey($template)->delete();

        return response()->json(['message' => 'Saved template deleted.']);
    }

    protected function validateInvoice(Request $request, ?PlatformInvoice $existing = null): array
    {
        $statuses = ['draft', 'sent', 'paid', 'void'];

        return $request->validate([
            'invoice_number' => [
                'sometimes',
                'string',
                'max:40',
                Rule::unique('platform_invoices', 'invoice_number')->ignore($existing?->id),
            ],
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'status' => ['required', Rule::in($statuses)],
            'template_id' => 'required|string|max:40',
            'currency' => 'sometimes|string|max:8',
            'issue_date' => 'required|date',
            'due_date' => 'nullable|date',
            'bill_to_name' => 'nullable|string|max:200',
            'bill_to_email' => 'nullable|email|max:200',
            'bill_to_phone' => 'nullable|string|max:60',
            'bill_to_address' => 'nullable|string|max:2000',
            'bill_to_tax_pin' => 'nullable|string|max:60',
            'bill_to_company_code' => 'nullable|string|max:45',
            'seller' => 'nullable|array',
            'seller.name' => 'nullable|string|max:200',
            'seller.email' => 'nullable|email|max:200',
            'seller.phone' => 'nullable|string|max:60',
            'seller.address' => 'nullable|string|max:2000',
            'seller.tax_pin' => 'nullable|string|max:60',
            'invoice_options' => 'nullable|array',
            'invoice_options.show_quantity' => 'nullable|boolean',
            'invoice_options.show_payment_details' => 'nullable|boolean',
            'invoice_options.payment_details' => 'nullable|string|max:5000',
            'invoice_options.show_etims_invoice_no' => 'nullable|boolean',
            'invoice_options.etims_invoice_no' => 'nullable|string|max:120',
            'invoice_options.watermark_enabled' => 'nullable|boolean',
            'invoice_options.watermark_mode' => 'nullable|in:name,logo,text',
            'invoice_options.watermark_text' => 'nullable|string|max:120',
            'invoice_options.watermark_logo_url' => 'nullable|string|max:500000',
            'invoice_options.brand_mode' => 'nullable|in:name,logo,both',
            'invoice_options.brand_name' => 'nullable|string|max:120',
            'invoice_options.brand_logo_url' => 'nullable|string|max:500000',
            'invoice_options.print_font_family' => 'nullable|string|max:40',
            'invoice_options.print_font_scale' => 'nullable|in:compact,standard,large,extra_large',
            'line_items' => 'required|array|min:1',
            'line_items.*.module_key' => 'nullable|string|max:80',
            'line_items.*.description' => 'required|string|max:500',
            'line_items.*.quantity' => 'nullable|numeric|min:0',
            'line_items.*.unit_price' => 'nullable|numeric',
            'line_items.*.amount' => 'nullable|numeric',
            'line_items.*.included' => 'nullable|boolean',
            'selected_modules' => 'nullable|array',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string|max:5000',
            'terms' => 'nullable|string|max:5000',
        ]);
    }

    protected function invoicePayload(PlatformInvoice $invoice): array
    {
        $payload = $invoice->toArray();
        $payload['organization'] = $invoice->organization
            ? $invoice->organization->only(['id', 'company_code', 'org_name'])
            : null;

        return $payload;
    }

    protected function findTenantOrganization(int $organizationId): Organization
    {
        $platformCode = config('erp.platform_company_code', 'PLATFORM');

        return Organization::query()
            ->where('id', $organizationId)
            ->where('company_code', '!=', $platformCode)
            ->firstOrFail();
    }
}
