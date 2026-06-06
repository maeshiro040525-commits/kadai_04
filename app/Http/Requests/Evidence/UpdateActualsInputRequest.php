<?php

namespace App\Http\Requests\Evidence;

use App\Support\EvidenceAddonCatalog;
use App\Support\EvidenceAddonStaffAssignmentCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;

class UpdateActualsInputRequest extends FormRequest
{
    /**
     * @var array<string, string>|null
     */
    private ?array $addonStaffSelectableDefinitionsCache = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'facility_id' => ['required', 'integer', 'exists:facilities,id'],
            'fiscal_year' => ['required', 'integer'],
            'annual' => ['required', 'array'],
            'annual.region_code' => ['nullable', 'string', 'max:50'],
            'annual.capacity' => ['required', 'integer', 'min:0'],
            'annual.facility_type' => ['required', 'string', 'max:255'],
            'annual.is_branch' => ['nullable', 'in:0,1'],
            'rule' => ['required', 'array'],
            'rule.category_1_percent' => ['required', 'numeric'],
            'rule.category_2_percent' => ['required', 'numeric'],
            'rule.category_3a' => ['required', 'integer', 'min:0'],
            'rule.category_3b' => ['required', 'integer', 'min:0'],
            'inputs' => ['nullable', 'array'],
            'inputs.*' => ['nullable', 'array'],
            'inputs.*.*' => ['nullable', 'array'],
            'inputs.*.*.*' => ['nullable', 'integer', 'min:0'],
            'addons' => ['nullable', 'array'],
            'addon_staff' => ['nullable', 'array'],
        ], $this->buildAddonValidationRules(), $this->buildAddonStaffValidationRules());
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAddonValidationRules(): array
    {
        $rules = [];

        foreach (EvidenceAddonCatalog::addonDefinitions() as $uiCode => $definition) {
            $rules["addons.$uiCode"] = ['nullable', 'array'];

            $type = $definition['type'] ?? null;
            if ($type === 'checkbox') {
                $rules["addons.$uiCode.*"] = ['nullable', 'in:1'];
            } elseif ($type === 'select') {
                $rules["addons.$uiCode.*"] = ['nullable', 'string', 'max:100'];
            } else {
                $rules["addons.$uiCode.*"] = ['nullable', 'integer', 'min:0'];
            }
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAddonStaffValidationRules(): array
    {
        $rules = [];
        $staffIdRules = ['nullable', 'integer', 'min:1'];
        if (Schema::hasTable('emploees')) {
            $staffIdRules[] = 'exists:emploees,id';
        }

        foreach (array_keys($this->addonStaffSelectableDefinitions()) as $uiCode) {
            $rules["addon_staff.$uiCode"] = ['nullable', 'array'];
            $rules["addon_staff.$uiCode.*"] = ['nullable', 'array'];
            $rules["addon_staff.$uiCode.*.*"] = $staffIdRules;
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    private function addonStaffSelectableDefinitions(): array
    {
        if ($this->addonStaffSelectableDefinitionsCache !== null) {
            return $this->addonStaffSelectableDefinitionsCache;
        }

        return $this->addonStaffSelectableDefinitionsCache = EvidenceAddonStaffAssignmentCatalog::selectableDefinitions();
    }
}
