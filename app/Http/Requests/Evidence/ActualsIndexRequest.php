<?php
namespace App\Http\Requests\Evidence;

use Illuminate\Foundation\Http\FormRequest;

class ActualsIndexRequest extends FormRequest
{
    /**
     * 実績入力画面の絞り込み条件（facility_id / fiscal_year）を検証する。
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'facility_id' => ['nullable', 'integer', 'exists:facilities,id'],
            'fiscal_year' => ['nullable', 'regex:/^\d{4}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'facility_id.integer' => '施設IDは数字で指定してください。',
            'facility_id.exists' => '指定された施設が見つかりません。',
            'fiscal_year.regex' => '年度は YYYY（例：2026）の形式で入力してください。',
        ];
    }
}
