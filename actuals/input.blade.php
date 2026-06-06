@extends('layouts.app')

@section('title', '実績（根拠入力）')

@section('content')
    <style>
        .compact-table { font-size: 0.8em; border-collapse: collapse; }
        .compact-table th, .compact-table td { padding: 2px 4px; }
        .compact-table input[type="number"],
        .compact-table input[type="checkbox"],
        .compact-table select { font-size: 0.9em; }
        .compact-table input[type="number"] { width: 50px; }
        .compact-table select { max-width: 120px; }
    </style>
    {{-- 根拠入力画面: 年度設定・区分ルール・年齢別人数を一体で入力する。 --}}
    <h1>実績（根拠入力）</h1>

    @if(session('success'))
        <div class="box">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="box">
            <p><strong>入力に問題があります：</strong></p>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 施設未作成 --}}
    @if(!$facility)
        <div class="box">
            <p>施設がありません。先に施設を作成してください。</p>
        </div>
        @return
    @endif

    {{-- フィルタ（施設・年度の切り替え） --}}
    <div class="box">
        <form method="GET" action="{{ route('evidence.actuals.input.index') }}">
            <label>施設</label>
            <select name="facility_id">
                @foreach($facilities as $f)
                    <option value="{{ $f->id }}" @selected($facilityId == $f->id)>
                        {{ $f->name }}（id: {{ $f->id }}）
                    </option>
                @endforeach
            </select>

            <label style="margin-left:12px;">年度</label>
            <input type="number" name="fiscal_year" value="{{ $fiscalYear }}" style="width:100px;">

            <button type="submit" style="margin-left:12px;">表示</button>
            <a href="{{ route('evidence.actuals.index', ['facility_id' => $facilityId, 'fiscal_year' => $fiscalYear]) }}" style="margin-left:12px;">実績を確認</a>
        </form>
    </div>

    <div class="box">
        {{-- 根拠入力フォーム本体 --}}
        <p class="muted">入力対象：2・3号 利用定員（0〜5歳）</p>

        <form method="POST" action="{{ route('evidence.actuals.input.update') }}">
            @csrf
            @method('PUT')

            <input type="hidden" name="facility_id" value="{{ $facilityId }}">
            <input type="hidden" name="fiscal_year" value="{{ $fiscalYear }}">

            <div style="margin: 0 0 12px 0;">
                <button type="submit">保存</button>
            </div>

            <table border="1" cellpadding="2" cellspacing="0" class="compact-table" style="margin-bottom:12px;">
                {{-- 年度設定・区分ルールの入力ブロック --}}
                <tbody>
                <tr>
                    <th>地域区分</th>
                    <td>
                        <input type="text"
                               name="annual[region_code]"
                               value="{{ old('annual.region_code', $annualInput['region_code'] ?? '') }}"
                               placeholder="その他地域">
                    </td>
                    <th>定員</th>
                    <td>
                        <input type="number"
                               name="annual[capacity]"
                               value="{{ old('annual.capacity', $annualInput['capacity'] ?? 0) }}"
                               min="0"
                               step="1">
                    </td>
                    <th>施設種別</th>
                    <td>
                        <input type="text"
                               name="annual[facility_type]"
                               value="{{ old('annual.facility_type', $annualInput['facility_type'] ?? '') }}">
                    </td>
                    <th>分園</th>
                    <td>
                        <input type="hidden" name="annual[is_branch]" value="0">
                        <input type="checkbox"
                               name="annual[is_branch]"
                               value="1"
                               @checked(old('annual.is_branch', $annualInput['is_branch'] ?? false))>
                    </td>
                    <th>区分1</th>
                    <td>
                        <input type="number"
                               name="rule[category_1_percent]"
                               value="{{ old('rule.category_1_percent', isset($ruleInput['category_1_percent']) ? (float)$ruleInput['category_1_percent'] : '') }}"
                               min="0"
                               step="0.01"
                               style="width:90px;">
                    </td>
                    <th>区分2</th>
                    <td>
                        <input type="number"
                               name="rule[category_2_percent]"
                               value="{{ old('rule.category_2_percent', isset($ruleInput['category_2_percent']) ? (float)$ruleInput['category_2_percent'] : '') }}"
                               min="0"
                               step="0.01"
                               style="width:90px;">
                    </td>
                    <th>区分3A</th>
                    <td>
                        <input type="number"
                               name="rule[category_3a]"
                               value="{{ old('rule.category_3a', isset($ruleInput['category_3a']) ? (int)$ruleInput['category_3a'] : '') }}"
                               min="0"
                               step="1"
                               style="width:90px;">
                    </td>
                    <th>区分3B</th>
                    <td>
                        <input type="number"
                               name="rule[category_3b]"
                               value="{{ old('rule.category_3b', isset($ruleInput['category_3b']) ? (int)$ruleInput['category_3b'] : '') }}"
                               min="0"
                               step="1"
                               style="width:90px;">
                    </td>
                </tr>
                </tbody>
            </table>

            <table border="1" cellpadding="2" cellspacing="0" class="compact-table">
                {{-- 0〜5歳の月次人数入力ブロック --}}
                <thead>
                    <tr>
                        <th rowspan="2">認定区分</th>
                        <th rowspan="2">項目</th>
                        @foreach($months as $idx => $ym)
                            <th colspan="{{ count($durationTypes) }}">
                                <div>{{ \Carbon\Carbon::parse($ym . '-01')->format('n月') }}</div>
                                @if($idx > 0)
                                    <button type="button"
                                            class="copy-prev-month"
                                            data-source-month="{{ $months[$idx - 1] }}"
                                            data-target-month="{{ $ym }}">
                                        前月コピー
                                    </button>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                    <tr>
                        @foreach($months as $ym)
                            @foreach($durationTypes as $dt)
                                <th>{{ $dt->label }}</th>
                            @endforeach
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $code => $label)
                        <tr>
                            @if($loop->first)
                                <td rowspan="{{ count($rows) + 1 }}">2・3号</td>
                            @endif
                            <th>{{ $label }}</th>
                            @foreach($months as $ym)
                                @php
                                    $divisor = $monthlyStaffingDivisorsByInputCode[$ym][$code] ?? null;
                                @endphp
                                @foreach($durationTypes as $dt)
                                    @php
                                        $v = $values[$code][$dt->code][$ym] ?? '';
                                    @endphp
                                    <td>
                                        <input type="number"
                                               name="inputs[{{ $code }}][{{ $dt->code }}][{{ $ym }}]"
                                               value="{{ old("inputs.$code.{$dt->code}.$ym", $v) }}"
                                               data-code="{{ $code }}"
                                               data-duration="{{ $dt->code }}"
                                               data-ym="{{ $ym }}"
                                               data-team-slot-divisor="{{ $divisor }}"
                                               min="0"
                                               step="1"
                                               style="width:36px; text-align:center;">
                                    </td>
                                @endforeach
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>

            {{-- 人割当枠・職員割当（別テーブル） --}}
            <table border="1" cellpadding="2" cellspacing="0" class="compact-table" style="margin-top:8px;">
                <thead>
                    <tr>
                        <th>項目</th>
                        @foreach($months as $ym)
                            <th>{{ \Carbon\Carbon::parse($ym . '-01')->format('n月') }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th>人割当枠</th>
                        @foreach($months as $ym)
                            @php
                                $minimumSlot = (int) ($monthlyMinimumStaffingSlots[$ym] ?? 0);
                            @endphp
                            <td style="text-align:center;">
                                <input type="number"
                                       value="{{ $minimumSlot }}"
                                       readonly
                                       disabled
                                       style="width:36px; background:#f5f5f5; text-align:center;">
                                <div class="muted" style="font-size:10px;">
                                    最低:
                                    <span data-team-slot-min="{{ $ym }}">{{ $minimumSlot }}</span>
                                </div>
                            </td>
                        @endforeach
                    </tr>
                    <tr>
                        <th>2・3号<br>職員割当</th>
                        @foreach($months as $ym)
                            @php
                                $minimumSlot = (int) ($monthlyMinimumStaffingSlots[$ym] ?? 0);
                                $cap23StaffValues = old("addon_staff.CAP23_MIN_STAFFING.$ym");
                                if ($cap23StaffValues === null) {
                                    $cap23StaffValues = $addonStaffValues['CAP23_MIN_STAFFING'][$ym] ?? [];
                                }
                                if (!is_array($cap23StaffValues)) {
                                    $cap23StaffValues = ($cap23StaffValues === '' || $cap23StaffValues === null)
                                        ? []
                                        : [$cap23StaffValues];
                                }
                                $normalizedCap23StaffValues = [];
                                foreach ($cap23StaffValues as $staffValueItem) {
                                    if ($staffValueItem === null || $staffValueItem === '') {
                                        continue;
                                    }
                                    $normalizedCap23StaffValues[] = (string) $staffValueItem;
                                }
                                $requiredRowCount = max($minimumSlot, count($normalizedCap23StaffValues), 1);
                                $cap23StaffValuesForRender = $normalizedCap23StaffValues;
                                while (count($cap23StaffValuesForRender) < $requiredRowCount) {
                                    $cap23StaffValuesForRender[] = '';
                                }
                            @endphp
                            <td>
                                <div style="display:flex; flex-direction:column; gap:2px;">
                                    <div class="cap23-staff-container"
                                         data-addon-ym="{{ $ym }}"
                                         style="display:flex; flex-direction:column; gap:2px;">
                                        @foreach($cap23StaffValuesForRender as $staffValue)
                                            <div class="cap23-staff-row" style="display:flex; gap:2px; align-items:center;">
                                                <select name="addon_staff[CAP23_MIN_STAFFING][{{ $ym }}][]"
                                                        data-addon-code="CAP23_MIN_STAFFING"
                                                        data-addon-ym="{{ $ym }}"
                                                        data-addon-field="staff_ids"
                                                        style="width:100px; font-size:0.8em;">
                                                    <option value="">未選択</option>
                                                    @foreach($teamCareStaffOptions as $staffId => $staffLabel)
                                                        <option value="{{ $staffId }}" @selected((string)$staffValue === (string)$staffId)>
                                                            {{ $staffLabel }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                                <button type="button" class="remove-cap23-staff" data-addon-ym="{{ $ym }}" style="font-size:0.8em;">-</button>
                                            </div>
                                        @endforeach
                                    </div>
                                    <button type="button" class="add-cap23-staff" data-addon-ym="{{ $ym }}" style="font-size:0.8em;">＋</button>
                                    <div class="muted" style="font-size:10px;">
                                        最低: <span data-cap23-staff-min="{{ $ym }}">{{ $minimumSlot }}</span>
                                    </div>
                                    <div data-cap23-staff-error="{{ $ym }}" style="color:#b00020; font-size:10px;"></div>
                                    @error("addon_staff.CAP23_MIN_STAFFING.$ym")
                                        <div style="color:#b00020; font-size:10px;">{{ $message }}</div>
                                    @enderror
                                </div>
                            </td>
                        @endforeach
                    </tr>
                </tbody>
            </table>

            <table border="1" cellpadding="2" cellspacing="0" class="compact-table" style="margin-top:12px;">
                <thead>
                    <tr>
                        <th>各種加算</th>
                        @foreach($months as $idx => $ym)
                            <th>
                                <div>{{ \Carbon\Carbon::parse($ym . '-01')->format('n月') }}</div>
                                @if($idx > 0)
                                    <button type="button"
                                            class="copy-prev-addon-month"
                                            data-source-month="{{ $months[$idx - 1] }}"
                                            data-target-month="{{ $ym }}">
                                        前月コピー
                                    </button>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($unifiedAddonDefinitions as $addonDef)
                        @if($addonDef['is_march_only']) @continue @endif
                        @php
                            $uiCode = $addonDef['ui_code'];
                            $addonType = $addonDef['type'];
                        @endphp
                        <tr>
                            <th>{{ $addonDef['label'] }}</th>
                            @foreach($months as $ym)
                                <td style="text-align:center;">
                                    @if($addonType === 'checkbox')
                                        {{-- チェックボックス型加算 --}}
                                        @php
                                            $isMarchOnly = $addonDef['is_march_only'] ?? false;
                                            $isMarchColumn = str_ends_with($ym, '-03');
                                            $showCheckbox = !$isMarchOnly || $isMarchColumn;

                                            $checked = old("addons.$uiCode.$ym");
                                            if ($checked === null) {
                                                $checked = (($addonValues[$uiCode][$ym]['is_selected'] ?? false) ? '1' : null);
                                            }
                                        @endphp
                                        @if(!$showCheckbox)
                                            <span style="color:#999;">—</span>
                                            <input type="hidden" name="addons[{{ $uiCode }}][{{ $ym }}]" value="">
                                        @else
                                        <input type="checkbox"
                                               name="addons[{{ $uiCode }}][{{ $ym }}]"
                                               value="1"
                                               data-addon-code="{{ $uiCode }}"
                                               data-addon-ym="{{ $ym }}"
                                               @checked((string)$checked === '1')>
                                        @if(in_array($uiCode, $staffAssignableUiCodes ?? [], true))
                                            @php
                                                $staffValues = old("addon_staff.$uiCode.$ym");
                                                if ($staffValues === null) {
                                                    $staffValues = $addonStaffValues[$uiCode][$ym] ?? [];
                                                }
                                                if (!is_array($staffValues)) {
                                                    $staffValues = ($staffValues === '' || $staffValues === null) ? [] : [$staffValues];
                                                }
                                                $normalizedStaffValues = [];
                                                foreach ($staffValues as $sv) {
                                                    if ($sv === null || $sv === '') continue;
                                                    $normalizedStaffValues[] = (string) $sv;
                                                }
                                                $staffValuesForRender = $normalizedStaffValues !== [] ? $normalizedStaffValues : [''];
                                            @endphp
                                            <div style="margin-top:4px; display:flex; flex-direction:column; gap:2px;">
                                                @foreach($staffValuesForRender as $staffValue)
                                                    <div style="display:flex; gap:4px; align-items:center;">
                                                        <select name="addon_staff[{{ $uiCode }}][{{ $ym }}][]"
                                                                style="width:140px; font-size:0.85em;">
                                                            <option value="">職員未選択</option>
                                                            @foreach($teamCareStaffOptions as $staffId => $staffLabel)
                                                                <option value="{{ $staffId }}" @selected((string)$staffValue === (string)$staffId)>
                                                                    {{ $staffLabel }}
                                                                </option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                        @endif
                                    @elseif($addonType === 'select')
                                        {{-- 系統選択型加算 --}}
                                        @php
                                            $isOnlyMarch = $addonDef['is_march_only'] ?? false;
                                            $isMarchColumn = str_ends_with($ym, '-03');
                                            $showSelect = !$isOnlyMarch || $isMarchColumn;

                                            $currentValue = old("addons.$uiCode.$ym");
                                            if ($currentValue === null) {
                                                $currentValue = $addonValues[$uiCode][$ym]['input_value'] ?? '';
                                            }

                                            // 職員割当: select型でも requires_staff_assignment の場合は表示する
                                            // 高齢者等活躍促進加算など、選択は3月のみでも職員割当は全月必要なケースに対応
                                            $showStaffAssignment = in_array($uiCode, $staffAssignableUiCodes ?? [], true);
                                        @endphp
                                        @if($showSelect)
                                            <select name="addons[{{ $uiCode }}][{{ $ym }}]"
                                                    data-select-addon-code="{{ $uiCode }}"
                                                    data-select-addon-ym="{{ $ym }}"
                                                    style="width:180px;">
                                                <option value="">なし</option>
                                                @foreach($addonDef['options'] as $optionCode => $option)
                                                    <option value="{{ $optionCode }}"
                                                            @selected((string) $currentValue === (string) $optionCode)>
                                                        {{ $option['name'] }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        @else
                                            <span style="color:#999;">—</span>
                                            <input type="hidden" name="addons[{{ $uiCode }}][{{ $ym }}]" value="">
                                        @endif
                                        @if($showStaffAssignment)
                                            @php
                                                $staffValues = old("addon_staff.$uiCode.$ym");
                                                if ($staffValues === null) {
                                                    $staffValues = $addonStaffValues[$uiCode][$ym] ?? [];
                                                }
                                                if (!is_array($staffValues)) {
                                                    $staffValues = ($staffValues === '' || $staffValues === null) ? [] : [$staffValues];
                                                }
                                                $normalizedStaffValues = [];
                                                foreach ($staffValues as $sv) {
                                                    if ($sv === null || $sv === '') continue;
                                                    $normalizedStaffValues[] = (string) $sv;
                                                }
                                                $staffValuesForRender = $normalizedStaffValues !== [] ? $normalizedStaffValues : [''];
                                            @endphp
                                            <div style="margin-top:4px; display:flex; flex-direction:column; gap:2px;">
                                                @foreach($staffValuesForRender as $staffValue)
                                                    <div style="display:flex; gap:4px; align-items:center;">
                                                        <select name="addon_staff[{{ $uiCode }}][{{ $ym }}][]"
                                                                style="width:140px; font-size:0.85em;">
                                                            <option value="">職員未選択</option>
                                                            @foreach($teamCareStaffOptions as $staffId => $staffLabel)
                                                                <option value="{{ $staffId }}" @selected((string)$staffValue === (string)$staffId)>
                                                                    {{ $staffLabel }}
                                                                </option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr>
                        <th>チーム保育加配加算（職員）</th>
                        @foreach($months as $ym)
                            <td>
                                @php
                                    $slotValue = old("addons.TEAM_STAFFING_COUNT.$ym");
                                    if ($slotValue === null) {
                                        $slotValue = $addonValues['TEAM_STAFFING_COUNT'][$ym]['input_value'] ?? '';
                                    }
                                    $staffValues = old("addon_staff.TEAM_STAFFING_COUNT.$ym");
                                    if ($staffValues === null) {
                                        $staffValues = $addonStaffValues['TEAM_STAFFING_COUNT'][$ym] ?? [];
                                    }
                                    if (!is_array($staffValues)) {
                                        $staffValues = ($staffValues === '' || $staffValues === null) ? [] : [$staffValues];
                                    }
                                    $normalizedStaffValues = [];
                                    foreach ($staffValues as $staffValueItem) {
                                        if ($staffValueItem === null || $staffValueItem === '') {
                                            continue;
                                        }
                                        $normalizedStaffValues[] = (string) $staffValueItem;
                                    }
                                    $staffValuesForRender = $normalizedStaffValues !== [] ? $normalizedStaffValues : [''];
                                @endphp
                                <div style="display:flex; flex-direction:column; gap:4px;">
                                    <div style="display:flex; align-items:center; gap:6px;">
                                        <input type="number"
                                               name="addons[TEAM_STAFFING_COUNT][{{ $ym }}]"
                                               value="{{ $slotValue }}"
                                               data-addon-code="TEAM_STAFFING_COUNT"
                                               data-addon-ym="{{ $ym }}"
                                               data-addon-field="input_value"
                                               data-team-slot-input="1"
                                               min="0"
                                               step="1"
                                               style="width:90px;">
                                    </div>
                                    <div class="team-care-staff-container"
                                         data-addon-ym="{{ $ym }}"
                                         style="display:flex; flex-direction:column; gap:4px;">
                                        @foreach($staffValuesForRender as $staffValue)
                                            <div class="team-care-staff-row" style="display:flex; gap:4px; align-items:center;">
                                                <select name="addon_staff[TEAM_STAFFING_COUNT][{{ $ym }}][]"
                                                        data-addon-code="TEAM_STAFFING_COUNT"
                                                        data-addon-ym="{{ $ym }}"
                                                        data-addon-field="staff_ids"
                                                        style="width:160px;">
                                                    <option value="">職員未選択</option>
                                                    @foreach($teamCareStaffOptions as $staffId => $staffLabel)
                                                        <option value="{{ $staffId }}" @selected((string)$staffValue === (string)$staffId)>
                                                            {{ $staffLabel }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                                <button type="button" class="remove-team-care-staff" data-addon-ym="{{ $ym }}">-</button>
                                            </div>
                                        @endforeach
                                    </div>
                                    <button type="button" class="add-team-care-staff" data-addon-ym="{{ $ym }}">＋</button>
                                </div>
                            </td>
                        @endforeach
                    </tr>
                    <tr>
                        <th>副食費徴収免除加算（対象児数）</th>
                        @foreach($months as $ym)
                            <td>
                                @php
                                    $foodFeeValue = old("addons.FOOD_FEE_EXEMPTION.$ym");
                                    if ($foodFeeValue === null) {
                                        $foodFeeValue = $addonValues['FOOD_FEE_EXEMPTION'][$ym]['input_value'] ?? '';
                                    }
                                @endphp
                                <input type="number"
                                       name="addons[FOOD_FEE_EXEMPTION][{{ $ym }}]"
                                       value="{{ $foodFeeValue }}"
                                       data-addon-code="FOOD_FEE_EXEMPTION"
                                       data-addon-ym="{{ $ym }}"
                                       data-addon-field="input_value"
                                       min="0"
                                       step="1"
                                       style="width:90px;">
                            </td>
                        @endforeach
                    </tr>
                    {{-- 3月のみ加算（下部にまとめて表示） --}}
                    @foreach($unifiedAddonDefinitions as $addonDef)
                        @if(!$addonDef['is_march_only']) @continue @endif
                        @php
                            $uiCode = $addonDef['ui_code'];
                            $addonType = $addonDef['type'];
                        @endphp
                        <tr>
                            <th>{{ $addonDef['label'] }}</th>
                            @foreach($months as $ym)
                                <td style="text-align:center;">
                                    @if($addonType === 'checkbox')
                                        @php
                                            $isMarchColumn = str_ends_with($ym, '-03');
                                            $checked = old("addons.$uiCode.$ym");
                                            if ($checked === null) {
                                                $checked = (($addonValues[$uiCode][$ym]['is_selected'] ?? false) ? '1' : null);
                                            }
                                        @endphp
                                        @if(!$isMarchColumn)
                                            <span style="color:#999;">—</span>
                                            <input type="hidden" name="addons[{{ $uiCode }}][{{ $ym }}]" value="">
                                        @else
                                            <input type="checkbox"
                                                   name="addons[{{ $uiCode }}][{{ $ym }}]"
                                                   value="1"
                                                   data-addon-code="{{ $uiCode }}"
                                                   data-addon-ym="{{ $ym }}"
                                                   @checked((string)$checked === '1')>
                                        @endif
                                    @elseif($addonType === 'select')
                                        @php
                                            $isMarchColumn = str_ends_with($ym, '-03');
                                            $currentValue = old("addons.$uiCode.$ym");
                                            if ($currentValue === null) {
                                                $currentValue = $addonValues[$uiCode][$ym]['input_value'] ?? '';
                                            }
                                        @endphp
                                        @if(!$isMarchColumn)
                                            <span style="color:#999;">—</span>
                                            <input type="hidden" name="addons[{{ $uiCode }}][{{ $ym }}]" value="">
                                        @else
                                            <select name="addons[{{ $uiCode }}][{{ $ym }}]"
                                                    style="width:180px;">
                                                <option value="">なし</option>
                                                @foreach($addonDef['options'] as $optionCode => $option)
                                                    <option value="{{ $optionCode }}"
                                                            @selected((string) $currentValue === (string) $optionCode)>
                                                        {{ $option['name'] }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        @endif
                                        @if(in_array($uiCode, $staffAssignableUiCodes ?? [], true))
                                            @php
                                                $staffValues = old("addon_staff.$uiCode.$ym");
                                                if ($staffValues === null) {
                                                    $staffValues = $addonStaffValues[$uiCode][$ym] ?? [];
                                                }
                                                if (!is_array($staffValues)) {
                                                    $staffValues = ($staffValues === '' || $staffValues === null) ? [] : [$staffValues];
                                                }
                                                $normalizedStaffValues = [];
                                                foreach ($staffValues as $sv) {
                                                    if ($sv === null || $sv === '') continue;
                                                    $normalizedStaffValues[] = (string) $sv;
                                                }
                                                $staffValuesForRender = $normalizedStaffValues !== [] ? $normalizedStaffValues : [''];
                                            @endphp
                                            <div style="margin-top:4px; display:flex; flex-direction:column; gap:2px;">
                                                @foreach($staffValuesForRender as $staffValue)
                                                    <div style="display:flex; gap:4px; align-items:center;">
                                                        <select name="addon_staff[{{ $uiCode }}][{{ $ym }}][]"
                                                                style="width:140px; font-size:0.85em;">
                                                            <option value="">職員未選択</option>
                                                            @foreach($teamCareStaffOptions as $staffId => $staffLabel)
                                                                <option value="{{ $staffId }}" @selected((string)$staffValue === (string)$staffId)>
                                                                    {{ $staffLabel }}
                                                                </option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if(!empty($calcErrors))
                {{-- 単価計算に必要な条件不足時のエラー表示 --}}
                <div class="box">
                    <p><strong>計算できません：</strong></p>
                    <ul>
                        @foreach($calcErrors as $msg)
                            <li>{{ $msg }}</li>
                        @endforeach
                    </ul>
                </div>
            @else
                {{-- 単価計算結果の月次表示 --}}
        <div class="box">
            <h2>計算結果</h2>
            <p class="muted">地域区分：{{ $regionCode }}</p>

            <table border="1" cellpadding="2" cellspacing="0" class="compact-table">
                <thead>
                <tr>
                    <th>項目</th>
                    @foreach($months as $ym)
                        <th>{{ \Carbon\Carbon::parse($ym . '-01')->format('n月') }}</th>
                    @endforeach
                </tr>
                </thead>
                <tbody>
                {{-- 年齢配置基準 --}}
                <tr>
                    <th>年齢配置基準（基本分）</th>
                    @foreach($months as $ym)
                        <td style="text-align:right;">{{ number_format((int)round($monthlyTotals[$ym] ?? 0)) }}</td>
                    @endforeach
                </tr>
                <tr>
                    <th>年齢配置基準（区分1・2）</th>
                    @foreach($months as $ym)
                        <td style="text-align:right;">{{ number_format((int)round($monthlyTotalsTi12[$ym] ?? 0)) }}</td>
                    @endforeach
                </tr>
                {{-- チーム保育加算 --}}
                <tr>
                    <th>チーム保育加算（基本分）</th>
                    @foreach($months as $ym)
                        <td style="text-align:right;">{{ number_format((int)round($monthlyTeamCare[$ym] ?? 0)) }}</td>
                    @endforeach
                </tr>
                <tr>
                    <th>チーム保育加算（区分1・2）</th>
                    @foreach($months as $ym)
                        <td style="text-align:right;">{{ number_format((int)round($monthlyTeamCareTi12[$ym] ?? 0)) }}</td>
                    @endforeach
                </tr>
                {{-- 4歳以上児年齢別加算 --}}
                <tr>
                    <th>4歳以上児年齢別加算（基本分）</th>
                    @foreach($months as $ym)
                        <td style="text-align:right;">{{ number_format((int)round($monthlyAge4[$ym] ?? 0)) }}</td>
                    @endforeach
                </tr>
                <tr>
                    <th>4歳以上児年齢別加算（区分1・2）</th>
                    @foreach($months as $ym)
                        <td style="text-align:right;">{{ number_format((int)round($monthlyAge4Ti12[$ym] ?? 0)) }}</td>
                    @endforeach
                </tr>
                {{-- 3歳児年齢別加算 --}}
                <tr>
                    <th>3歳児年齢別加算（基本分）</th>
                    @foreach($months as $ym)
                        <td style="text-align:right;">{{ number_format((int)round($monthlyAge3[$ym] ?? 0)) }}</td>
                    @endforeach
                </tr>
                <tr>
                    <th>3歳児年齢別加算（区分1・2）</th>
                    @foreach($months as $ym)
                        <td style="text-align:right;">{{ number_format((int)round($monthlyAge3Ti12[$ym] ?? 0)) }}</td>
                    @endforeach
                </tr>
                {{-- 1歳児年齢別加算 --}}
                <tr>
                    <th>1歳児年齢別加算（基本分）</th>
                    @foreach($months as $ym)
                        <td style="text-align:right;">{{ number_format((int)round($monthlyAge1[$ym] ?? 0)) }}</td>
                    @endforeach
                </tr>
                <tr>
                    <th>1歳児年齢別加算（区分1・2）</th>
                    @foreach($months as $ym)
                        <td style="text-align:right;">{{ number_format((int)round($monthlyAge1Ti12[$ym] ?? 0)) }}</td>
                    @endforeach
                </tr>
                {{-- 固定額加算 --}}
                @foreach($fixedAmountAddonRows as $row)
                    <tr>
                        <th>{{ $row['label'] }}（基本分）</th>
                        @foreach($months as $ym)
                            <td style="text-align:right;">{{ number_format((int) round($row['basic_amounts'][$ym] ?? 0)) }}</td>
                        @endforeach
                    </tr>
                    @if(collect($row['ti_amounts'])->some(fn ($amount) => (float) $amount !== 0.0))
                        <tr>
                            <th>{{ $row['label'] }}（区分1・2）</th>
                            @foreach($months as $ym)
                                <td style="text-align:right;">{{ number_format((int) round($row['ti_amounts'][$ym] ?? 0)) }}</td>
                            @endforeach
                        </tr>
                    @endif
                @endforeach
                {{-- 系統選択型加算 --}}
                @foreach($selectAmountAddonRows as $row)
                    <tr>
                        <th>{{ $row['label'] }}（基本分）</th>
                        @foreach($months as $ym)
                            <td style="text-align:right;">{{ number_format((int) round($row['basic_amounts'][$ym] ?? 0)) }}</td>
                        @endforeach
                    </tr>
                    @if(collect($row['ti_amounts'])->some(fn ($amount) => (float) $amount !== 0.0))
                        <tr>
                            <th>{{ $row['label'] }}（区分1・2）</th>
                            @foreach($months as $ym)
                                <td style="text-align:right;">{{ number_format((int) round($row['ti_amounts'][$ym] ?? 0)) }}</td>
                            @endforeach
                        </tr>
                    @endif
                @endforeach
                {{-- 処遇改善等加算（区分3） --}}
                @if(collect($monthlyCat3 ?? [])->some(fn ($v) => (float) $v !== 0.0))
                <tr>
                    <th>処遇改善等加算（区分3）</th>
                    @foreach($months as $ym)
                        <td style="text-align:right;">{{ number_format((int) round($monthlyCat3[$ym] ?? 0)) }}</td>
                    @endforeach
                </tr>
                @endif
                </tbody>
            </table>
        </div>
            @endif
        </form>
    </div>

    <script>
        const toNonNegativeNumber = (value) => {
            const parsed = Number(value);
            if (!Number.isFinite(parsed) || parsed < 0) {
                return 0;
            }
            return parsed;
        };

        const minimumSlotsForMonth = (month) => {
            // 全 duration type のインプットを集め、同じ code の値を合算してから divisor で割る
            const ageInputs = document.querySelectorAll(`input[data-ym="${month}"][data-code][data-team-slot-divisor]`);
            // code ごとに値を合計
            const sumByCode = {};
            const divisorByCode = {};
            ageInputs.forEach((input) => {
                const code = input.dataset.code;
                const divisor = Number(input.dataset.teamSlotDivisor || '');
                if (!Number.isFinite(divisor) || divisor <= 0) {
                    return;
                }
                sumByCode[code] = (sumByCode[code] || 0) + toNonNegativeNumber(input.value);
                divisorByCode[code] = divisor;
            });
            let sum = 0;
            for (const code of Object.keys(sumByCode)) {
                sum += sumByCode[code] / divisorByCode[code];
            }

            return Math.max(0, Math.round(sum));
        };

        const updateCap23RemoveButtons = (container, minimumSlots = 0) => {
            const removeButtons = container.querySelectorAll('.remove-cap23-staff');
            const minimumRows = Math.max(1, minimumSlots);
            const shouldHide = removeButtons.length <= minimumRows;
            removeButtons.forEach((button) => {
                button.style.display = shouldHide ? 'none' : '';
            });
        };

        const createCap23StaffRow = (container, selectedValue = '') => {
            const template = container.querySelector('.cap23-staff-row');
            if (!template) return null;

            const row = template.cloneNode(true);
            const select = row.querySelector('select[data-addon-field="staff_ids"]');
            if (select) {
                select.value = selectedValue;
                select.setCustomValidity('');
            }

            return row;
        };

        const ensureCap23StaffRows = (month, minimumSlots) => {
            const container = document.querySelector(`.cap23-staff-container[data-addon-ym="${month}"]`);
            if (!container) {
                return;
            }

            const minimumRows = Math.max(1, minimumSlots);
            while (container.querySelectorAll('.cap23-staff-row').length < minimumRows) {
                const row = createCap23StaffRow(container, '');
                if (!row) {
                    break;
                }
                container.appendChild(row);
            }

            updateCap23RemoveButtons(container, minimumSlots);
        };

        const validateCap23StaffMonth = (month, minimumSlots) => {
            const container = document.querySelector(`.cap23-staff-container[data-addon-ym="${month}"]`);
            const minimumNode = document.querySelector(`[data-cap23-staff-min="${month}"]`);
            const errorBox = document.querySelector(`[data-cap23-staff-error="${month}"]`);
            if (minimumNode) {
                minimumNode.textContent = String(minimumSlots);
            }
            if (!container) {
                return true;
            }

            const selects = Array.from(container.querySelectorAll('select[data-addon-field="staff_ids"]'));
            const uniqueSelectedStaffCount = new Set(
                selects
                    .map((select) => select.value)
                    .filter((value) => value !== '')
            ).size;
            selects.forEach((select) => select.setCustomValidity(''));
            if (errorBox) {
                errorBox.textContent = '';
            }
            // TODO: 本番運用時に有効化。開発中は職員割当の最低人数チェックをスキップ
            return true;
        };

        const refreshTeamSlotMonth = (month) => {
            if (!month) return true;

            const minimumSlots = minimumSlotsForMonth(month);
            const minimumNode = document.querySelector(`[data-team-slot-min="${month}"]`);
            if (minimumNode) {
                minimumNode.textContent = String(minimumSlots);
            }

            const slotInput = document.querySelector(`input[data-team-slot-input="1"][data-addon-ym="${month}"]`);
            if (slotInput) {
                slotInput.min = '0';
                slotInput.setCustomValidity('');
            }

            ensureCap23StaffRows(month, minimumSlots);
            const isCap23StaffValid = validateCap23StaffMonth(month, minimumSlots);

            return isCap23StaffValid;
        };

        const refreshAllTeamSlotMonths = () => {
            const months = Array.from(
                document.querySelectorAll('input[data-team-slot-input="1"][data-addon-ym]')
            ).map((input) => input.dataset.addonYm);
            const uniqueMonths = Array.from(new Set(months.filter((month) => Boolean(month))));
            uniqueMonths.forEach((month) => refreshTeamSlotMonth(month));
        };

        // 「前月コピー」ボタン:
        // 同じ年齢コードの前月値を対象月へ一括反映する。
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.copy-prev-month');
            if (!btn) return;

            const sourceMonth = btn.dataset.sourceMonth;
            const targetMonth = btn.dataset.targetMonth;
            if (!sourceMonth || !targetMonth) return;

            const targetInputs = document.querySelectorAll(`input[data-ym="${targetMonth}"][data-code][data-duration]`);
            targetInputs.forEach((targetInput) => {
                const code = targetInput.dataset.code;
                const duration = targetInput.dataset.duration;
                const sourceInput = document.querySelector(`input[data-ym="${sourceMonth}"][data-code="${code}"][data-duration="${duration}"]`);
                if (sourceInput) {
                    targetInput.value = sourceInput.value;
                }
            });

            refreshTeamSlotMonth(targetMonth);
        });

        // 各種加算テーブルの「前月コピー」ボタン:
        // 同じ加算コードの前月値を対象月へ一括反映する。
        const resolveAddonField = (input) => {
            if (input.dataset.addonField) {
                return input.dataset.addonField;
            }
            return input.type === 'checkbox' ? 'checked' : 'input_value';
        };

        const updateTeamCareRemoveButtons = (container) => {
            const removeButtons = container.querySelectorAll('.remove-team-care-staff');
            const shouldHide = removeButtons.length <= 1;
            removeButtons.forEach((button) => {
                button.style.display = shouldHide ? 'none' : '';
            });
        };

        const createTeamCareStaffRow = (container, selectedValue = '') => {
            const template = container.querySelector('.team-care-staff-row');
            if (!template) return null;

            const row = template.cloneNode(true);
            const select = row.querySelector('select[data-addon-field="staff_ids"]');
            if (select) {
                select.value = selectedValue;
            }
            return row;
        };

        const replaceTeamCareStaffRows = (container, values) => {
            const template = container.querySelector('.team-care-staff-row');
            if (!template) return;

            container.innerHTML = '';
            const renderValues = values.length > 0 ? values : [''];
            renderValues.forEach((value) => {
                const row = template.cloneNode(true);
                const select = row.querySelector('select[data-addon-field="staff_ids"]');
                if (select) {
                    select.value = value;
                }
                container.appendChild(row);
            });
            updateTeamCareRemoveButtons(container);
        };

        document.querySelectorAll('.team-care-staff-container').forEach((container) => {
            updateTeamCareRemoveButtons(container);
        });
        document.querySelectorAll('.cap23-staff-container').forEach((container) => {
            const month = container.dataset.addonYm;
            const minimumSlots = month ? minimumSlotsForMonth(month) : 0;
            updateCap23RemoveButtons(container, minimumSlots);
        });

        document.addEventListener('click', (e) => {
            const addButton = e.target.closest('.add-team-care-staff');
            if (addButton) {
                const ym = addButton.dataset.addonYm;
                if (!ym) return;

                const container = document.querySelector(`.team-care-staff-container[data-addon-ym="${ym}"]`);
                if (!container) return;

                const row = createTeamCareStaffRow(container, '');
                if (!row) return;

                container.appendChild(row);
                updateTeamCareRemoveButtons(container);
                return;
            }

            const removeButton = e.target.closest('.remove-team-care-staff');
            if (removeButton) {
                const row = removeButton.closest('.team-care-staff-row');
                const container = removeButton.closest('.team-care-staff-container');
                if (!row || !container) return;

                const fallbackRow = row.cloneNode(true);
                const fallbackSelect = fallbackRow.querySelector('select[data-addon-field="staff_ids"]');
                if (fallbackSelect) {
                    fallbackSelect.value = '';
                }

                row.remove();
                if (!container.querySelector('.team-care-staff-row')) {
                    container.appendChild(fallbackRow);
                }
                updateTeamCareRemoveButtons(container);
                return;
            }

            const cap23AddButton = e.target.closest('.add-cap23-staff');
            if (cap23AddButton) {
                const ym = cap23AddButton.dataset.addonYm;
                if (!ym) return;

                const container = document.querySelector(`.cap23-staff-container[data-addon-ym="${ym}"]`);
                if (!container) return;

                const row = createCap23StaffRow(container, '');
                if (!row) return;

                container.appendChild(row);
                updateCap23RemoveButtons(container, minimumSlotsForMonth(ym));
                refreshTeamSlotMonth(ym);
                return;
            }

            const cap23RemoveButton = e.target.closest('.remove-cap23-staff');
            if (cap23RemoveButton) {
                const row = cap23RemoveButton.closest('.cap23-staff-row');
                const container = cap23RemoveButton.closest('.cap23-staff-container');
                if (!row || !container) return;

                const ym = cap23RemoveButton.dataset.addonYm;
                const minimumSlots = ym ? minimumSlotsForMonth(ym) : 0;
                const minimumRows = Math.max(1, minimumSlots);
                const currentRows = container.querySelectorAll('.cap23-staff-row').length;
                if (currentRows <= minimumRows) {
                    return;
                }

                row.remove();
                updateCap23RemoveButtons(container, minimumSlots);
                if (ym) {
                    refreshTeamSlotMonth(ym);
                }
                return;
            }

            const btn = e.target.closest('.copy-prev-addon-month');
            if (!btn) return;

            const sourceMonth = btn.dataset.sourceMonth;
            const targetMonth = btn.dataset.targetMonth;
            if (!sourceMonth || !targetMonth) return;

            const sourceTeamCareContainer = document.querySelector(
                `.team-care-staff-container[data-addon-ym="${sourceMonth}"]`
            );
            const targetTeamCareContainer = document.querySelector(
                `.team-care-staff-container[data-addon-ym="${targetMonth}"]`
            );
            if (sourceTeamCareContainer && targetTeamCareContainer) {
                const sourceStaffValues = Array.from(
                    sourceTeamCareContainer.querySelectorAll('select[data-addon-field="staff_ids"]')
                )
                    .map((select) => select.value)
                    .filter((value) => value !== '');

                replaceTeamCareStaffRows(targetTeamCareContainer, sourceStaffValues);
            }

            const targetInputs = document.querySelectorAll(`[data-addon-ym="${targetMonth}"][data-addon-code]`);
            targetInputs.forEach((targetInput) => {
                const code = targetInput.dataset.addonCode;
                const targetField = resolveAddonField(targetInput);
                if (targetField === 'staff_ids') {
                    return;
                }
                const sourceInputs = Array.from(
                    document.querySelectorAll(`[data-addon-ym="${sourceMonth}"][data-addon-code="${code}"]`)
                );
                const sourceInput = sourceInputs.find((candidate) => resolveAddonField(candidate) === targetField);
                if (!sourceInput) return;

                if (targetInput.type === 'checkbox') {
                    targetInput.checked = sourceInput.checked;
                } else {
                    targetInput.value = sourceInput.value;
                }
            });

            // 系統選択型加算の前月コピー（統合テーブル内）
            const targetSelects = document.querySelectorAll(`select[data-select-addon-ym="${targetMonth}"]`);
            targetSelects.forEach((targetSelect) => {
                const code = targetSelect.dataset.selectAddonCode;
                const sourceSelect = document.querySelector(`select[data-select-addon-ym="${sourceMonth}"][data-select-addon-code="${code}"]`);
                if (sourceSelect) {
                    targetSelect.value = sourceSelect.value;
                }
            });

            refreshTeamSlotMonth(targetMonth);
        });

        document.addEventListener('input', (e) => {
            const ageInput = e.target.closest('input[data-code][data-duration][data-team-slot-divisor]');
            if (ageInput) {
                refreshTeamSlotMonth(ageInput.dataset.ym);
                return;
            }

            const slotInput = e.target.closest('input[data-team-slot-input="1"][data-addon-ym]');
            if (slotInput) {
                refreshTeamSlotMonth(slotInput.dataset.addonYm);
            }
        });

        document.addEventListener('change', (e) => {
            const cap23Select = e.target.closest('select[data-addon-code="CAP23_MIN_STAFFING"][data-addon-ym]');
            if (cap23Select) {
                refreshTeamSlotMonth(cap23Select.dataset.addonYm);
            }
        });

        const actualsForm = document.querySelector('form[action="{{ route('evidence.actuals.input.update') }}"]');
        if (actualsForm) {
            actualsForm.addEventListener('submit', (e) => {
                let allValid = true;
                const slotInputs = actualsForm.querySelectorAll('input[data-team-slot-input="1"][data-addon-ym]');
                slotInputs.forEach((slotInput) => {
                    if (!refreshTeamSlotMonth(slotInput.dataset.addonYm)) {
                        allValid = false;
                    }
                });

                if (!allValid) {
                    e.preventDefault();
                    const firstInvalid = actualsForm.querySelector(
                        'input[data-team-slot-input="1"]:invalid, select[data-addon-code="CAP23_MIN_STAFFING"]:invalid'
                    );
                    if (firstInvalid) {
                        firstInvalid.reportValidity();
                        firstInvalid.focus();
                    }
                }
            });
        }

        refreshAllTeamSlotMonths();

    </script>
@endsection
