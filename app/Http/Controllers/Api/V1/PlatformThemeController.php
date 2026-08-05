<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Erp\CapabilityGate;
use App\Services\Sales\ClassicPosThemeSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PlatformThemeController extends Controller
{
    public function show()
    {
        $org = $this->platformOrganization();
        $sales = app(CapabilityGate::class)->forOrganization($org)->moduleSettings('sales');
        $normalized = ClassicPosThemeSettings::normalize($sales);

        return response()->json([
            'classic_pos_theme_template' => $normalized['classic_pos_theme_template'],
            'classic_pos_theme_colors' => $normalized['classic_pos_theme_colors'],
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'classic_pos_theme_template' => 'sometimes|string|in:'.implode(',', ClassicPosThemeSettings::THEME_TEMPLATES),
            'classic_pos_theme_colors' => 'sometimes|array',
            'classic_pos_theme_colors.workspace' => 'sometimes|nullable|string|max:9',
            'classic_pos_theme_colors.header' => 'sometimes|nullable|string|max:9',
            'classic_pos_theme_colors.footer' => 'sometimes|nullable|string|max:9',
            'classic_pos_theme_colors.button' => 'sometimes|nullable|string|max:9',
            'classic_pos_theme_colors.select' => 'sometimes|nullable|string|max:9',
        ]);

        if (
            $data === []
            && ! $request->exists('classic_pos_theme_template')
            && ! $request->exists('classic_pos_theme_colors')
        ) {
            throw ValidationException::withMessages([
                'classic_pos_theme_template' => ['Provide a theme template or custom colors.'],
            ]);
        }

        $org = $this->platformOrganization();
        $settings = $org->module_settings ?? [];
        $sales = is_array($settings['sales'] ?? null) ? $settings['sales'] : [];

        if (array_key_exists('classic_pos_theme_template', $data) || $request->exists('classic_pos_theme_template')) {
            $sales['classic_pos_theme_template'] = ClassicPosThemeSettings::normalizeThemeTemplate(
                $data['classic_pos_theme_template'] ?? $request->input('classic_pos_theme_template'),
            );
        }
        // Empty `{}` / `[]` must clear overrides — Laravel may omit empty arrays from validated().
        if (array_key_exists('classic_pos_theme_colors', $data) || $request->exists('classic_pos_theme_colors')) {
            $sales['classic_pos_theme_colors'] = ClassicPosThemeSettings::normalizeThemeColors(
                $data['classic_pos_theme_colors'] ?? $request->input('classic_pos_theme_colors'),
            );
        }

        $org->putModuleSettingsSection('sales', $sales);
        $normalized = ClassicPosThemeSettings::normalize($sales);

        return response()->json([
            'classic_pos_theme_template' => $normalized['classic_pos_theme_template'],
            'classic_pos_theme_colors' => $normalized['classic_pos_theme_colors'],
            'message' => 'Platform theme saved.',
        ]);
    }

    protected function platformOrganization(): Organization
    {
        $org = Organization::query()
            ->where('company_code', config('erp.platform_company_code', 'PLATFORM'))
            ->first();

        if (! $org) {
            throw ValidationException::withMessages([
                'organization' => ['Platform organization not found.'],
            ]);
        }

        return $org;
    }
}
