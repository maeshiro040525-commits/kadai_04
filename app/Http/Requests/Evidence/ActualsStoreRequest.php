<?php
namespace App\Http\Requests\Evidence;

use Illuminate\Foundation\Http\FormRequest;

class ActualsStoreRequest extends FormRequest
{
    /**
     * 実績入力保存リクエスト。
     * actual[補助金コード][年月] 形式の配列を検証する。
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // 金額は空欄許容だが、入力される場合は0以上の整数のみ許可する。
        return [
            'facility_id' => ['required', 'integer', 'exists:facilities,id'],
            'fiscal_year' => ['required', 'integer'],
            'actual' => ['nullable', 'array'],
            'actual.*' => ['nullable', 'array'],
            'actual.*.*' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
