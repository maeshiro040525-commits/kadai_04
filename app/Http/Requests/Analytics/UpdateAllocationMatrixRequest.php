<?php

namespace App\Http\Requests\Analytics;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAllocationMatrixRequest extends FormRequest
{
    /**
     * 配置マトリクス更新リクエスト。
     * 30分スロット配列を一括で受け取る。
     */
    public function authorize(): bool
    {
        // 認証導入後に施設単位の権限制御を追加する。
        return true;
    }

    public function rules(): array
    {
        // rows は画面全行を送る想定。
        // day_type + start/end + 必要人数2項目を必須で検証する。
        return [
            'facility_id' => ['required', 'integer', 'exists:facilities,id'],
            'year_month' => ['required', 'regex:/^\d{4}-\d{2}$/'],

            // 固定枠を全件送る想定
            'rows' => ['required', 'array'],

            'rows.*.day_type' => ['required', 'in:weekday,saturday'],
            'rows.*.start_time' => ['required', 'date_format:H:i'],
            'rows.*.end_time' => ['required', 'date_format:H:i'],

            // 必要
            'rows.*.required_nursery_teacher_count' => ['required', 'integer', 'min:0'],
            'rows.*.required_other_staff_count' => ['required', 'integer', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        // 形式チェックだけでは拾えない業務ルールを後段で追加する。
        $validator->after(function ($validator) {
            $rows = $this->input('rows', []);

            // start < end
            foreach ($rows as $i => $r) {
                $st = $r['start_time'] ?? null;
                $en = $r['end_time'] ?? null;
                if ($st && $en && $st >= $en) {
                    $validator->errors()->add("rows.$i.end_time", '終了時間は開始時間より後にしてください。');
                }
            }

            // 重複禁止（day_type + start + end）
            $seen = [];
            foreach ($rows as $i => $r) {
                $key = ($r['day_type'] ?? '').'|'.($r['start_time'] ?? '').'|'.($r['end_time'] ?? '');
                if (isset($seen[$key])) {
                    $validator->errors()->add("rows.$i.start_time", '同じ枠が重複しています。');
                }
                $seen[$key] = true;
            }
        });
    }
}
