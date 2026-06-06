<?php

namespace App\Services\Evidence;

use App\Models\OfficialPrice;
use App\Support\EvidenceAddonCatalog;
use App\Support\EvidenceInputCodeCatalog;
use App\Support\FacilityTypeCodeCatalog;
use App\Support\OfficialPriceItemCodeCatalog;
use App\Support\OverCapacityRateTable;
use App\Support\SubsidyCodes;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class MonthlyActualsCalculator
{
    public function resolveFacilityTypeCode(?string $facilityType): ?string
    {
        return FacilityTypeCodeCatalog::resolve($facilityType);
    }

    /**
     * 認定区分ごとに、公定価格lookupで使う定員を解決する。
     *
     * - 保育所(HOIKUEN)は認定が2・3号一本＝従来の capacity をそのまま使う（後方互換）。
     * - 認定こども園(KODOMOEN)は認定で定員系統が分かれる：
     *     2・3号(cert=23) → capacity_nursery（2号＋3号 利用定員）
     *     1号(cert=1)     → capacity_kindergarten（1号 利用定員）
     *   ※ こども園で認定別定員が null の場合、あえて従来 capacity にフォールバックしない。
     *     認可定員でlookupすると誤った定員区分（例: 兼城90→81-90帯）を引くため、null を返し
     *     呼び出し側で「認定別定員が未設定」エラーにする方が安全（黙って間違うより止めて気づく）。
     */
    public function resolveCapacityForCertification(
        ?string $facilityTypeCode,
        string $certificationCode,
        ?int $capacity,
        ?int $capacityNursery,
        ?int $capacityKindergarten
    ): ?int {
        if ($facilityTypeCode !== 'KODOMOEN') {
            // 保育所など：従来どおり単一 capacity
            return $capacity;
        }

        // 認定こども園：認定区分で定員系統を切り替える
        return match ($certificationCode) {
            '23' => $capacityNursery,      // 2・3号 ← 2号＋3号 利用定員
            '1' => $capacityKindergarten,  // 1号   ← 1号 利用定員
            default => $capacity,          // 想定外の認定は安全側で従来値
        };
    }

    /**
     * @param array<string, float> $pricesByAge
     * @return array<int, string>
     */
    public function buildMissingAgeErrors(array $pricesByAge, string $label): array
    {
        $errors = [];
        foreach (EvidenceInputCodeCatalog::requiredAges() as $age) {
            if (!array_key_exists($age, $pricesByAge)) {
                $errors[] = "単価が不足しています：{$age}（{$label}）";
            }
        }

        return $errors;
    }

    /**
     * 選択された固定額 addon に対して、official_prices 側の単価不足を検出する。
     *
     * 戻り値:
     * - key: `addons.{UI_CODE}.{YYYY-MM}`
     * - value: 画面表示用エラーメッセージ
     *
     * @param array<int, string> $months
     * @param array<string, array<string, array{is_selected: bool, input_value: ?int|string|null}>> $addons
     * @param EloquentCollection<int, OfficialPrice> $prices
     * @return array<string, string>
     */
    public function buildFixedAmountAddonValidationMessages(
        array $months,
        array $addons,
        EloquentCollection $prices
    ): array {
        // 【箱1：単価の引き表】official_pricesのフラットな行を [item_code][年齢][成分(basic/ti/c)] の入れ子辞書へ組み替える。
        // 以降の全計算ブロックがこの引き表から単価を引く（例: [...]['ALL']['basic']）。
        $priceComponentsByItemAndAge = [];
        foreach ($prices as $price) {
            $componentKey = $this->normalizeComponentKey($price->component_key);
            if ($componentKey === null) {
                continue;
            }

            $priceComponentsByItemAndAge[$price->item_code][$price->age_code][$componentKey] = (float) $price->value;
        }

        $messages = [];
        // 固定額加算の単価不足を検証する（計算は後段の計算ブロックで行う。ここは入力チェックのみ）
        foreach (EvidenceAddonCatalog::fixedAmountAddonDefinitions() as $uiCode => $definition) {
            $components = $this->resolveCommonPriceComponents(
                $this->buildPriceComponentsByItemAndAge(
                    $priceComponentsByItemAndAge,
                    array_values(array_filter([
                        $definition['basic_item_code'] ?? null,
                        $definition['ti_item_code'] ?? null,
                        $definition['rate_c_item_code'] ?? null,
                    ]))
                )
            );
            $hasBasic = array_key_exists('basic', $components);
            $expectsTi12 = trim((string) ($definition['ti_item_code'] ?? '')) !== ''
                || trim((string) ($definition['rate_c_item_code'] ?? '')) !== '';
            $hasTi12 = $this->hasTi12Components($components);

            foreach ($months as $ym) {
                if (!(bool) ($addons[$uiCode][$ym]['is_selected'] ?? false)) {
                    continue;
                }

                $field = sprintf('addons.%s.%s', $uiCode, $ym);
                $label = (string) ($definition['label'] ?? $uiCode);
                if (!$hasBasic) {
                    $messages[$field] = sprintf(
                        '%s の%sは official_prices の基本分が未設定です。',
                        $ym,
                        $label
                    );

                    continue;
                }

                $isTreatmentEnabled = (bool) ($addons['TREATMENT_IMPROVEMENT'][$ym]['is_selected'] ?? false);
                if ($isTreatmentEnabled && $expectsTi12 && !$hasTi12) {
                    $messages[$field] = sprintf(
                        '%s の%sは official_prices の区分1・2計算分が未設定です。',
                        $ym,
                        $label
                    );
                }
            }
        }

        return $messages;
    }

    /**
     * @return EloquentCollection<int, OfficialPrice>
     */
    /**
     * 選択された系統選択型 addon に対して、official_prices 側の単価不足を検出する。
     *
     * @param array<int, string> $months
     * @param array<string, array<string, array{is_selected: bool, input_value: ?int|string|null}>> $addons
     * @param EloquentCollection<int, OfficialPrice> $prices
     * @return array<string, string>
     */
    public function buildSelectAddonValidationMessages(
        array $months,
        array $addons,
        EloquentCollection $prices
    ): array {
        $priceComponentsByItemAndAge = [];
        foreach ($prices as $price) {
            $componentKey = $this->normalizeComponentKey($price->component_key);
            if ($componentKey === null) {
                continue;
            }
            $priceComponentsByItemAndAge[$price->item_code][$price->age_code][$componentKey] = (float) $price->value;
        }

        $messages = [];
        $selectAddonDefinitions = EvidenceAddonCatalog::selectAddonDefinitions();
        foreach ($selectAddonDefinitions as $uiCode => $definition) {
            $options = $definition['options'] ?? [];

            foreach ($months as $ym) {
                if (!(bool) ($addons[$uiCode][$ym]['is_selected'] ?? false)) {
                    continue;
                }

                $selectedCode = (string) ($addons[$uiCode][$ym]['input_value'] ?? '');
                if ($selectedCode === '' || !isset($options[$selectedCode])) {
                    continue;
                }

                $selectedOption = $options[$selectedCode];
                $optionItemCodes = array_values(array_filter([
                    $selectedOption['item_code_basic'] ?? null,
                ]));

                if ($optionItemCodes === []) {
                    continue;
                }

                $optionComponents = $this->resolveCommonPriceComponents(
                    $this->buildPriceComponentsByItemAndAge(
                        $priceComponentsByItemAndAge,
                        $optionItemCodes
                    )
                );

                $field = sprintf('addons.%s.%s', $uiCode, $ym);
                $label = (string) ($definition['label'] ?? $uiCode);
                if (!array_key_exists('basic', $optionComponents)) {
                    $messages[$field] = sprintf(
                        '%s の%s（%s）は official_prices の基本分が未設定です。',
                        $ym,
                        $label,
                        $selectedOption['name'] ?? $selectedCode
                    );
                }
            }
        }

        return $messages;
    }

    public function queryOfficialPrices(
        int $fiscalYear,
        string $regionCode,
        string $facilityTypeCode,
        int $capacity
    ): EloquentCollection {
        return OfficialPrice::query()
            ->where('fiscal_year', $fiscalYear)
            ->where('region_code', $regionCode)
            ->where('facility_type_code', $facilityTypeCode)
            ->whereIn('certification_code', OfficialPriceItemCodeCatalog::certificationCodes())
            ->whereIn('item_code', $this->officialPriceItemCodesForSync())
            ->where('capacity_min', '<=', $capacity)
            ->where('capacity_max', '>=', $capacity)
            ->get(['item_code', 'age_code', 'component_key', 'value']);
    }

    /**
     * @param EloquentCollection<int, OfficialPrice> $prices
     * @param array<int, string> $itemCodes
     * @return array<string, array{basic?: float, ti?: float, c?: float}>
     */
    public function buildPriceComponentsByAge(EloquentCollection $prices, array $itemCodes): array
    {
        $componentsByAge = [];
        $allowedItemCodes = array_flip($itemCodes);
        foreach ($prices as $price) {
            if (!isset($allowedItemCodes[$price->item_code])) {
                continue;
            }

            $componentKey = $this->normalizeComponentKey($price->component_key);
            if ($componentKey === null) {
                continue;
            }

            $componentsByAge[$price->age_code][$componentKey] = (float) $price->value;
        }

        return $componentsByAge;
    }

    /**
     * @param array<int, string> $months
     * @param array<string, array<string, int|string|float|null>> $inputs 合算済み園児数（後方互換）
     * @param array<string, array<string, array{is_selected: bool, input_value: ?int}>> $addons
     * @param EloquentCollection<int, OfficialPrice>|null $officialPrices
     * @param array<string, array<string, array<string, int|string|float|null>>>|null $inputsByDuration duration別園児数 [code][duration][ym]
     * @return array<string, array<string, float>>|null
     */
    public function buildMonthlyTotalsForSync(
        int $fiscalYear,
        array $months,
        ?string $regionCode,
        int $capacity,
        string $facilityType,
        float $category1Percent,
        float $category2Percent,
        array $inputs,
        array $addons,
        ?EloquentCollection $officialPrices = null,
        ?array $inputsByDuration = null,
        int $category3a = 0,
        int $category3b = 0,
        int $facilityCapacity = 0,
        bool $isBranch = false
    ): ?array {
        // ===== 準備フェーズ：単価をロード・整形し、結果の箱を用意する =====
        // 施設種別の表示名（例「保育園」）を計算用コード（HOIKUEN）へ変換。地域 or 種別が無ければ計算不能でnull。
        $facilityTypeCode = $this->resolveFacilityTypeCode($facilityType);
        if (!$regionCode || !$facilityTypeCode) {
            return null;
        }

        $class12ItemCodesByComponent = OfficialPriceItemCodeCatalog::class12ItemCodesByComponent();
        $class12ShortItemCodesByComponent = OfficialPriceItemCodeCatalog::class12ShortItemCodesByComponent();
        $teamCareItemCodesByComponent = OfficialPriceItemCodeCatalog::teamCareItemCodesByComponent();
        // 公定価格をロード（引数で渡されればそれを、無ければDBから取得）。
        // 取得対象は officialPriceItemCodesForSync() の"買い物リスト"に載る item_code のみ。
        $prices = $officialPrices ?? $this->queryOfficialPrices(
            $fiscalYear,
            $regionCode,
            $facilityTypeCode,
            $capacity
        );

        $priceComponentsByItemAndAge = [];
        foreach ($prices as $price) {
            $componentKey = $this->normalizeComponentKey($price->component_key);
            if ($componentKey !== null) {
                $priceComponentsByItemAndAge[$price->item_code][$price->age_code][$componentKey] = (float) $price->value;
            }
        }

        // 標準時間用の単価
        $unitComponentsByAge = $this->buildPriceComponentsByAge(
            $prices,
            array_values($class12ItemCodesByComponent)
        );

        // 短時間用の単価
        $shortUnitComponentsByAge = $this->buildPriceComponentsByAge(
            $prices,
            array_values($class12ShortItemCodesByComponent)
        );

        // ガード：必要な年齢の基本単価が1つでも欠けていたら計算不能としてnullを返す
        // （画面に出る「単価が不足しています」の正体。公定価格マスタ未投入の検知）
        foreach (EvidenceInputCodeCatalog::requiredAges() as $age) {
            if (!$this->hasCommonComponents($unitComponentsByAge[$age] ?? [])) {
                return null;
            }
        }

        $codeToAge = EvidenceInputCodeCatalog::codeToAge();
        // duration別のinputsがある場合、duration別に計算する
        $hasDurationInputs = $inputsByDuration !== null && $inputsByDuration !== [];

        // 各加算の【結果の箱】を月別0で初期化（ループ内の += で積み上げるため0スタート）
        $monthlyTotals = array_fill_keys($months, 0.0);
        $monthlyTotalsTi12 = array_fill_keys($months, 0.0);
        $monthlyTeamCare = array_fill_keys($months, 0.0);
        $monthlyTeamCareTi12 = array_fill_keys($months, 0.0);
        $monthlyAge4 = array_fill_keys($months, 0.0);
        $monthlyAge4Ti12 = array_fill_keys($months, 0.0);
        $monthlyAge3 = array_fill_keys($months, 0.0);
        $monthlyAge3Ti12 = array_fill_keys($months, 0.0);
        $monthlyAge1 = array_fill_keys($months, 0.0);
        $monthlyAge1Ti12 = array_fill_keys($months, 0.0);
        $monthlyFixedAmountAddons = $this->initializeFixedAmountAddonMonthlyTotals($months);
        $monthlyAddonTotals = [
            'age4' => &$monthlyAge4,
            'age4_ti12' => &$monthlyAge4Ti12,
            'age3' => &$monthlyAge3,
            'age3_ti12' => &$monthlyAge3Ti12,
            'age1' => &$monthlyAge1,
            'age1_ti12' => &$monthlyAge1Ti12,
        ];

        // duration別の単価マップ: duration_code => componentsByAge
        $unitComponentsByDuration = [
            'standard' => $unitComponentsByAge,
            'short' => $shortUnitComponentsByAge,
        ];

        // ===== 心臓部：基本分単価＋処遇改善Ⅰ(区分1,2)＝年齢別「人数×単価」を月合計に積み上げ =====
        foreach (EvidenceInputCodeCatalog::codes() as $code) {   // 年齢別コード(CAP_23_AGE0..5)を1つずつ
            $age = $codeToAge[$code] ?? null;
            if ($age === null) {
                continue;
            }

            if ($hasDurationInputs) {   // duration別データがある場合のみ計算（実運用では全呼び出し元が必ず渡す。無ければ基本分0でスキップ）
                // duration別に計算: 標準×標準単価 + 短×短単価
                foreach ($unitComponentsByDuration as $duration => $durationComponents) {
                    $unitPrice = (float) ($durationComponents[$age]['basic'] ?? 0);
                    $class12AddonYen = (float) ($durationComponents[$age]['ti'] ?? 0);
                    $class12AddonRateC = (float) ($durationComponents[$age]['c'] ?? 0);
                    foreach ($months as $ym) {
                        $isTreatmentEnabled = (bool) ($addons['TREATMENT_IMPROVEMENT'][$ym]['is_selected'] ?? false);
                        $count = (float) ($inputsByDuration[$code][$duration][$ym] ?? 0);   // その年齢・区分・月の人数
                        $monthlyTotals[$ym] += $this->calculateBasicAmount($count, $unitPrice);   // 基本分＝人数×単価 を積む
                        if ($isTreatmentEnabled) {   // 処遇改善ONの月だけ処遇改善Ⅰを別の箱に積む
                            $monthlyTotalsTi12[$ym] += $this->calculateTi12Amount(
                                $count,
                                $class12AddonYen,
                                $category1Percent,
                                $category2Percent,
                                $class12AddonRateC
                            );
                        }
                    }
                }
            }
            // （旧・後方互換の else 分岐＝合算inputs×標準単価 は死蔵のため削除。2026-06-03。
            //   全呼び出し元が duration別データを渡すため到達しなかった）
        }

        $ageSpecificAddonRules = EvidenceAddonCatalog::ageSpecificAddonRules();
        $ageSpecificAddonComponentsByCode = [];
        foreach ($ageSpecificAddonRules as $addonCode => $rule) {
            $ageSpecificAddonComponentsByCode[$addonCode] = $this->buildPriceComponentsByItemAndAge(
                $priceComponentsByItemAndAge,
                $rule['item_codes']
            );
        }

        foreach ($ageSpecificAddonRules as $addonCode => $rule) {
            $monthlyAddonBase = &$monthlyAddonTotals[$rule['result_key']];
            $monthlyAddonTi12 = &$monthlyAddonTotals[$rule['result_ti_key']];
            $inputAges = $rule['input_ages'] ?? [];
            if (!is_array($inputAges) || $inputAges === []) {
                continue;
            }

            $inputCodes = EvidenceInputCodeCatalog::codesForAges($inputAges);
            if ($inputCodes === []) {
                continue;
            }

            foreach ($months as $ym) {
                $isAddonEnabled = (bool) ($addons[$addonCode][$ym]['is_selected'] ?? false);
                if (!$isAddonEnabled) {
                    continue;
                }

                $isTreatmentEnabled = (bool) ($addons['TREATMENT_IMPROVEMENT'][$ym]['is_selected'] ?? false);
                foreach ($inputCodes as $inputCode) {
                    $age = $codeToAge[$inputCode] ?? null;
                    if ($age === null) {
                        continue;
                    }

                    $addonComponents = $ageSpecificAddonComponentsByCode[$addonCode][$age] ?? [];
                    if (!$this->hasCommonComponents($addonComponents)) {
                        continue;
                    }

                    $count = (float) ($inputs[$inputCode][$ym] ?? 0);
                    $monthlyAddonBase[$ym] += $this->calculateBasicAmount(
                        $count,
                        (float) ($addonComponents['basic'] ?? 0)
                    );
                    if ($isTreatmentEnabled) {
                        $monthlyAddonTi12[$ym] += $this->calculateTi12Amount(
                            $count,
                            (float) ($addonComponents['ti'] ?? 0),
                            $category1Percent,
                            $category2Percent,
                            (float) ($addonComponents['c'] ?? 0)
                        );
                    }
                }
            }
        }

        $teamCareComponents = $this->buildPriceComponentsByItemAndAge(
            $priceComponentsByItemAndAge,
            array_values($teamCareItemCodesByComponent)
        )['ALL'] ?? [];
        if ($this->hasCommonComponents($teamCareComponents)) {
            $monthlyChildrenCounts = $this->buildMonthlyChildrenCounts($inputs, $months);
            foreach ($months as $ym) {
                $isTreatmentEnabled = (bool) ($addons['TREATMENT_IMPROVEMENT'][$ym]['is_selected'] ?? false);
                $childrenCount = (float) ($monthlyChildrenCounts[$ym] ?? 0);
                $inputValue = (float) ($addons['TEAM_STAFFING_COUNT'][$ym]['input_value'] ?? 0);
                $monthlyTeamCare[$ym] += $this->calculateBasicAmount(
                    $childrenCount,
                    (float) ($teamCareComponents['basic'] ?? 0)
                ) * $inputValue;
                if ($isTreatmentEnabled) {
                    $monthlyTeamCareTi12[$ym] += $this->calculateTi12Amount(
                        $childrenCount,
                        (float) ($teamCareComponents['ti'] ?? 0),
                        $category1Percent,
                        $category2Percent,
                        (float) ($teamCareComponents['c'] ?? 0)
                    ) * $inputValue;
                }
            }
        }

        $monthlyTotalsForSync = [
            SubsidyCodes::BASIC_UNIT_PRICE => $monthlyTotals,
            SubsidyCodes::BASIC_UNIT_PRICE_TI12 => $monthlyTotalsTi12,
            SubsidyCodes::TEAM_CARE => $monthlyTeamCare,
            SubsidyCodes::TEAM_CARE_TI12 => $monthlyTeamCareTi12,
            SubsidyCodes::AGE4 => $monthlyAge4,
            SubsidyCodes::AGE4_TI12 => $monthlyAge4Ti12,
            SubsidyCodes::AGE3 => $monthlyAge3,
            SubsidyCodes::AGE3_TI12 => $monthlyAge3Ti12,
            SubsidyCodes::AGE1 => $monthlyAge1,
            SubsidyCodes::AGE1_TI12 => $monthlyAge1Ti12,
        ];

        // 月別の全園児数を事前計算（冷暖房費加算など1人あたり単価の加算で使用）
        $monthlyTotalChildren = array_fill_keys($months, 0.0);
        foreach (EvidenceInputCodeCatalog::codes() as $code) {
            foreach ($months as $ym) {
                $monthlyTotalChildren[$ym] += (float) ($inputs[$code][$ym] ?? 0);
            }
        }

        // ===== 固定額系加算（主任専任・事務職員雇上費・冷暖房費 等）：1ループでカタログ定義の全加算を処理 =====
        foreach (EvidenceAddonCatalog::fixedAmountAddonDefinitions() as $uiCode => $definition) {
            // この加算の単価(basic/ti/c)を引き表から取得（item_codeが無い成分は array_filter で除外）
            $components = $this->resolveCommonPriceComponents(
                $this->buildPriceComponentsByItemAndAge(
                    $priceComponentsByItemAndAge,
                    array_values(array_filter([
                        $definition['basic_item_code'] ?? null,
                        $definition['ti_item_code'] ?? null,
                        $definition['rate_c_item_code'] ?? null,
                    ]))
                )
            );
            if ($components === []) {
                continue;
            }

            $baseCode = $definition['subsidy_code'];
            // 冷暖房費・除雪費は1人あたり単価のため園児数を掛ける。
            // 他の固定額加算（主任保育士専任・事務職員雇上費・降灰除去費等）はDB値が施設全体額のためそのまま使用。
            $isPerChildAddon = in_array($baseCode, [
                SubsidyCodes::HEATING_COOLING,
                SubsidyCodes::SNOW_REMOVAL,
            ], true);

            foreach ($months as $ym) {
                $isAddonEnabled = (bool) ($addons[$uiCode][$ym]['is_selected'] ?? false);
                if (!$isAddonEnabled) {
                    continue;
                }

                $basicAmount = (float) ($components['basic'] ?? 0);
                if ($isPerChildAddon) {
                    $basicAmount *= $monthlyTotalChildren[$ym];   // タイプA：1人あたり単価なので園児数を掛ける（冷暖房 120×88 等）
                }

                $isTreatmentEnabled = (bool) ($addons['TREATMENT_IMPROVEMENT'][$ym]['is_selected'] ?? false);
                $tiCode = SubsidyCodes::ti12Code($baseCode);
                $hasTi = array_key_exists($tiCode, $monthlyFixedAmountAddons)
                    && $this->hasTi12Components($components);

                $tiAmount = 0.0;
                if ($isTreatmentEnabled && $hasTi) {
                    $tiAmount = $this->calculateTi12Amount(
                        1.0,
                        (float) ($components['ti'] ?? 0),
                        $category1Percent,
                        $category2Percent,
                        (float) ($components['c'] ?? 0)
                    );
                }

                // 施設全体額の加算は1人あたり単価に変換し10円未満切り捨て後、園児数を掛ける
                if (!$isPerChildAddon && $monthlyTotalChildren[$ym] > 0) {
                    [$basicAmount, $tiAmount] = $this->applyPerChildRounding(
                        $basicAmount,
                        $tiAmount,
                        $monthlyTotalChildren[$ym]
                    );
                }

                $monthlyFixedAmountAddons[$baseCode][$ym] += $basicAmount;
                if ($hasTi) {
                    $monthlyFixedAmountAddons[$tiCode][$ym] += $tiAmount;
                }
            }
        }

        // ===== 系統選択型加算（栄養管理・療育支援・減価償却・賃借料 等）=====
        // 特徴：input_value に「選んだ段階(tier)のコード」が入り、その段階の単価を引いて計算する。
        //   （checkbox型が is_selected だけなのに対し、select型は input_value で段階を指定）
        //   per-child(減価償却/賃借料)＝×園児数 ／ それ以外＝施設額を1人按分、の2タイプは固定額と同じ。
        $monthlySelectAddons = $this->initializeSelectAddonMonthlyTotals($months);
        $selectAddonDefinitions = EvidenceAddonCatalog::selectAddonDefinitions();
        foreach ($selectAddonDefinitions as $uiCode => $definition) {
            $baseCode = $uiCode; // ui_code == base_code for select addons
            $options = $definition['options'] ?? [];   // この加算の選択肢一覧（例 栄養管理の A/B/C）
            if ($options === []) {
                continue;
            }

            foreach ($months as $ym) {
                $isAddonEnabled = (bool) ($addons[$uiCode][$ym]['is_selected'] ?? false);
                if (!$isAddonEnabled) {
                    continue;
                }

                // input_value には選択された subsidy_master の code が格納されている
                $selectedCode = (string) ($addons[$uiCode][$ym]['input_value'] ?? '');
                if ($selectedCode === '' || !isset($options[$selectedCode])) {
                    continue;
                }

                $selectedOption = $options[$selectedCode];
                $optionItemCodes = array_values(array_filter([
                    $selectedOption['item_code_basic'] ?? null,
                    $selectedOption['item_code_ti'] ?? null,
                    $selectedOption['item_code_c'] ?? null,
                ]));

                if ($optionItemCodes === []) {
                    continue;
                }

                $optionComponents = $this->resolveCommonPriceComponents(
                    $this->buildPriceComponentsByItemAndAge(
                        $priceComponentsByItemAndAge,
                        $optionItemCodes
                    )
                );

                if ($optionComponents === []) {
                    continue;
                }

                $basicAmount = (float) ($optionComponents['basic'] ?? 0);

                // TI（区分１・２）計算
                $isTreatmentEnabled = (bool) ($addons['TREATMENT_IMPROVEMENT'][$ym]['is_selected'] ?? false);
                $hasTi = ($selectedOption['item_code_ti'] ?? null) !== null
                    && ($selectedOption['item_code_c'] ?? null) !== null;
                $tiCode = SubsidyCodes::ti12Code($baseCode);
                $hasTiSlot = array_key_exists($tiCode, $monthlySelectAddons)
                    && $this->hasTi12Components($optionComponents);

                $tiAmount = 0.0;
                if ($isTreatmentEnabled && $hasTi && $hasTiSlot) {
                    $tiAmount = $this->calculateTi12Amount(
                        1.0,
                        (float) ($optionComponents['ti'] ?? 0),
                        $category1Percent,
                        $category2Percent,
                        (float) ($optionComponents['c'] ?? 0)
                    );
                }

                // 減価償却費・賃借料は per-child 単価のため園児数を直接掛ける。
                // 他の select addon は施設全体額のため per-child rounding を適用。
                $isPerChildSelectAddon = in_array($baseCode, [
                    SubsidyCodes::DEPRECIATION,
                    SubsidyCodes::RENT,
                ], true);

                if ($isPerChildSelectAddon && $monthlyTotalChildren[$ym] > 0) {
                    $basicAmount *= $monthlyTotalChildren[$ym];
                } elseif ($monthlyTotalChildren[$ym] > 0) {
                    [$basicAmount, $tiAmount] = $this->applyPerChildRounding(
                        $basicAmount,
                        $tiAmount,
                        $monthlyTotalChildren[$ym]
                    );
                }

                if (array_key_exists($baseCode, $monthlySelectAddons)) {
                    $monthlySelectAddons[$baseCode][$ym] += $basicAmount;
                }
                if ($hasTiSlot) {
                    $monthlySelectAddons[$tiCode][$ym] += $tiAmount;
                }
            }
        }

        // ===== 夜間保育加算（年齢別 × 夜間単価）＝基本分ループの夜間版。$isTreatmentEnabledは月ごとに取り直し（漏れなし）=====
        $monthlyNightCare = array_fill_keys($months, 0.0);
        $monthlyNightCareTi12 = array_fill_keys($months, 0.0);
        $nightCareItemCodes = [
            'HOIKUEN_23_NIGHT_CARE_ADDON_YEN',
            'HOIKUEN_23_NIGHT_CARE_TREATMENT_YEN',
            'HOIKUEN_23_NIGHT_CARE_RATE_C',
        ];
        $nightCareComponentsByAge = $this->buildPriceComponentsByItemAndAge(
            $priceComponentsByItemAndAge,
            $nightCareItemCodes
        );
        if ($nightCareComponentsByAge !== []) {
            // 月×年齢別の園児数を集計
            $monthlyChildrenByAge = [];
            foreach (EvidenceInputCodeCatalog::codes() as $code) {
                $age = $codeToAge[$code] ?? null;
                if ($age === null) {
                    continue;
                }
                foreach ($months as $ym) {
                    $monthlyChildrenByAge[$ym][$age] = ($monthlyChildrenByAge[$ym][$age] ?? 0.0)
                        + (float) ($inputs[$code][$ym] ?? 0);
                }
            }

            foreach ($months as $ym) {
                $isNightCareEnabled = (bool) ($addons['NIGHT_CARE'][$ym]['is_selected'] ?? false);
                if (!$isNightCareEnabled) {
                    continue;
                }

                $isTreatmentEnabled = (bool) ($addons['TREATMENT_IMPROVEMENT'][$ym]['is_selected'] ?? false);
                foreach ($nightCareComponentsByAge as $ageCode => $ageComponents) {
                    if (!$this->hasCommonComponents($ageComponents)) {
                        continue;
                    }
                    $ageCount = (float) ($monthlyChildrenByAge[$ym][$ageCode] ?? 0);
                    if ($ageCount <= 0) {
                        continue;
                    }
                    $monthlyNightCare[$ym] += $this->calculateBasicAmount(
                        $ageCount,
                        (float) ($ageComponents['basic'] ?? 0)
                    );
                    if ($isTreatmentEnabled) {
                        $monthlyNightCareTi12[$ym] += $this->calculateTi12Amount(
                            $ageCount,
                            (float) ($ageComponents['ti'] ?? 0),
                            $category1Percent,
                            $category2Percent,
                            (float) ($ageComponents['c'] ?? 0)
                        );
                    }
                }
            }
        }
        $monthlyTotalsForSync[SubsidyCodes::NIGHT_CARE] = $monthlyNightCare;
        $nightCareTiCode = SubsidyCodes::ti12Code(SubsidyCodes::NIGHT_CARE);
        $monthlyTotalsForSync[$nightCareTiCode] = $monthlyNightCareTi12;

        // ========== 調整部分（減額）==========

        // --- ⑰ 分園の場合の減額 ---
        $monthlyBranchFacility = array_fill_keys($months, 0.0);
        if ($isBranch) {
            $branchRate = (float) ($priceComponentsByItemAndAge['HOIKUEN_23_BRANCH_FACILITY_RATE']['ALL']['basic'] ?? 10);
            foreach ($months as $ym) {
                $base = ($monthlyTotalsForSync[SubsidyCodes::BASIC_UNIT_PRICE][$ym] ?? 0)
                    + ($monthlyTotalsForSync[SubsidyCodes::BASIC_UNIT_PRICE_TI12][$ym] ?? 0);
                if ($base > 0) {
                    $monthlyBranchFacility[$ym] = -1 * floor($base * $branchRate / 100);
                }
            }
        }
        $monthlyTotalsForSync[SubsidyCodes::BRANCH_FACILITY] = $monthlyBranchFacility;

        // --- ⑱ 施設長未設置の減額 ---
        $monthlyDirectorNotAssigned = array_fill_keys($months, 0.0);
        $monthlyDirectorNotAssignedTi12 = array_fill_keys($months, 0.0);
        $directorDeductionBasic = (float) ($priceComponentsByItemAndAge['HOIKUEN_23_DIRECTOR_NOT_ASSIGNED_DEDUCTION_YEN']['ALL']['basic'] ?? 0);
        $directorDeductionTi = (float) ($priceComponentsByItemAndAge['HOIKUEN_23_DIRECTOR_NOT_ASSIGNED_TREATMENT_YEN']['ALL']['ti'] ?? 0);
        $directorDeductionRateC = (float) ($priceComponentsByItemAndAge['HOIKUEN_23_DIRECTOR_NOT_ASSIGNED_RATE_C']['ALL']['c'] ?? 0);
        if ($directorDeductionBasic > 0) {
            foreach ($months as $ym) {
                if (!(bool) ($addons['DIRECTOR_NOT_ASSIGNED'][$ym]['is_selected'] ?? false)) {
                    continue;
                }
                $totalChildren = $monthlyTotalChildren[$ym];
                if ($totalChildren <= 0) {
                    continue;
                }
                $isTreatmentEnabled = (bool) ($addons['TREATMENT_IMPROVEMENT'][$ym]['is_selected'] ?? false);
                $monthlyDirectorNotAssigned[$ym] = -1 * $this->calculateBasicAmount($totalChildren, $directorDeductionBasic);
                if ($isTreatmentEnabled) {
                    $monthlyDirectorNotAssignedTi12[$ym] = -1 * $this->calculateTi12Amount(
                        $totalChildren,
                        $directorDeductionTi,
                        $category1Percent,
                        $category2Percent,
                        $directorDeductionRateC
                    );
                }
            }
        }
        $monthlyTotalsForSync[SubsidyCodes::DIRECTOR_NOT_ASSIGNED] = $monthlyDirectorNotAssigned;
        $monthlyTotalsForSync[SubsidyCodes::DIRECTOR_NOT_ASSIGNED_TI12] = $monthlyDirectorNotAssignedTi12;

        // --- ⑲ 土曜閉所の減額（選択段階のrate × 対象項目合計をマイナス計上。基本+TI+年齢別+夜間を参照＝順序依存）---
        $monthlySaturdayClosure = array_fill_keys($months, 0.0);
        {
            // 土曜閉所の各段階のrate値を取得
            $saturdayClosureRates = [];
            $saturdayClosureItemCodes = [
                SubsidyCodes::SATURDAY_CLOSURE_1DAY => 'HOIKUEN_23_SATURDAY_CLOSURE_1DAY_RATE',
                SubsidyCodes::SATURDAY_CLOSURE_2DAYS => 'HOIKUEN_23_SATURDAY_CLOSURE_2DAYS_RATE',
                SubsidyCodes::SATURDAY_CLOSURE_3PLUS_DAYS => 'HOIKUEN_23_SATURDAY_CLOSURE_3PLUS_RATE',
                SubsidyCodes::SATURDAY_CLOSURE_ALL => 'HOIKUEN_23_SATURDAY_CLOSURE_ALL_RATE',
            ];
            foreach ($saturdayClosureItemCodes as $stageCode => $itemCode) {
                $saturdayClosureRates[$stageCode] = (float) ($priceComponentsByItemAndAge[$itemCode]['ALL']['basic'] ?? 0);
            }

            foreach ($months as $ym) {
                // selectAddonのうちSATURDAY_CLOSUREで選択された段階を取得
                $selectedStage = null;
                foreach ($saturdayClosureItemCodes as $stageCode => $itemCode) {
                    if ((bool) ($addons[$stageCode][$ym]['is_selected'] ?? false)) {
                        $selectedStage = $stageCode;
                        break;
                    }
                }
                // select addons format check: SATURDAY_CLOSURE ui_code
                if ($selectedStage === null) {
                    $saturdayClosureAddon = $addons['SATURDAY_CLOSURE'][$ym] ?? null;
                    if ($saturdayClosureAddon !== null && (bool) ($saturdayClosureAddon['is_selected'] ?? false)) {
                        $inputValue = $saturdayClosureAddon['input_value'] ?? null;
                        if ($inputValue !== null && isset($saturdayClosureRates[$inputValue])) {
                            $selectedStage = $inputValue;
                        }
                    }
                }
                if ($selectedStage === null) {
                    continue;
                }
                $rate = $saturdayClosureRates[$selectedStage] ?? 0;
                if ($rate <= 0) {
                    continue;
                }
                // 対象: ⑥基本分+⑦TI+⑧3歳児+⑧TI+⑨4歳以上児+⑨TI+⑩1歳児+⑩TI+⑫夜間保育+⑫TI
                $base = ($monthlyTotalsForSync[SubsidyCodes::BASIC_UNIT_PRICE][$ym] ?? 0)
                    + ($monthlyTotalsForSync[SubsidyCodes::BASIC_UNIT_PRICE_TI12][$ym] ?? 0)
                    + ($monthlyTotalsForSync[SubsidyCodes::AGE3][$ym] ?? 0)
                    + ($monthlyTotalsForSync[SubsidyCodes::AGE3_TI12][$ym] ?? 0)
                    + ($monthlyTotalsForSync[SubsidyCodes::AGE4][$ym] ?? 0)
                    + ($monthlyTotalsForSync[SubsidyCodes::AGE4_TI12][$ym] ?? 0)
                    + ($monthlyTotalsForSync[SubsidyCodes::AGE1][$ym] ?? 0)
                    + ($monthlyTotalsForSync[SubsidyCodes::AGE1_TI12][$ym] ?? 0)
                    + ($monthlyNightCare[$ym] ?? 0)
                    + ($monthlyNightCareTi12[$ym] ?? 0);
                $monthlySaturdayClosure[$ym] = -1 * floor($base * $rate);
            }
        }
        $monthlyTotalsForSync[SubsidyCodes::SATURDAY_CLOSURE] = $monthlySaturdayClosure;

        // --- ⑳ 定員超過の減額（定員と園児数→rate表。それまでの全項目合計に(100-rate)/100を掛ける＝ほぼ最後に実行。副食費はこの後に加算され対象外）---
        $monthlyOverCapacity = array_fill_keys($months, 0.0);
        {
            // facilityCapacity is passed as parameter to this method - need to use it
            // Get capacity from facility_settings
            foreach ($months as $ym) {
                if (!(bool) ($addons['CHRONIC_OVER_CAPACITY'][$ym]['is_selected'] ?? false)) {
                    continue;
                }
                $totalChildren = (int) $monthlyTotalChildren[$ym];
                if ($totalChildren <= 0) {
                    continue;
                }
                $rate = OverCapacityRateTable::getRateByCount($facilityCapacity, $totalChildren);
                if ($rate === null) {
                    continue;
                }
                // 対象: 全項目合計（⑥~⑲、⑯副食費除く）
                // = monthlyTotalsForSync全部 + monthlyFixedAmountAddons全部 + monthlySelectAddons全部
                //   + monthlyDirectorNotAssigned + monthlySaturdayClosure（これらはマイナス値なので合計に含めると減額される）
                $totalForMonth = 0.0;
                foreach ($monthlyTotalsForSync as $code => $monthlyValues) {
                    $totalForMonth += ($monthlyValues[$ym] ?? 0);
                }
                foreach ($monthlyFixedAmountAddons as $code => $monthlyValues) {
                    $totalForMonth += ($monthlyValues[$ym] ?? 0);
                }
                foreach ($monthlySelectAddons as $code => $monthlyValues) {
                    $totalForMonth += ($monthlyValues[$ym] ?? 0);
                }
                // rate is XX/100, so deduction = total * (1 - rate/100)
                $monthlyOverCapacity[$ym] = -1 * floor($totalForMonth * (100 - $rate) / 100);
            }
        }
        $monthlyTotalsForSync[SubsidyCodes::CHRONIC_OVER_CAPACITY] = $monthlyOverCapacity;

        // --- 処遇改善等加算（区分3）の計算 ---
        $monthlyCat3 = array_fill_keys($months, 0.0);
        if ($category3a > 0 || $category3b > 0) {
            $cat3Part1Price = (float) ($priceComponentsByItemAndAge['HOIKUEN_23_TREATMENT_CAT3_PART1_YEN']['ALL']['basic'] ?? 0);
            $cat3Part2Price = (float) ($priceComponentsByItemAndAge['HOIKUEN_23_TREATMENT_CAT3_PART2_YEN']['ALL']['basic'] ?? 0);
            $cat3FacilityTotal = $cat3Part1Price * $category3a + $cat3Part2Price * $category3b;

            if ($cat3FacilityTotal > 0) {
                foreach ($months as $ym) {
                    $totalChildren = $monthlyTotalChildren[$ym];
                    if ($totalChildren <= 0) {
                        continue;
                    }
                    $perChild = floor($cat3FacilityTotal / $totalChildren / 10) * 10;
                    $monthlyCat3[$ym] = $perChild * $totalChildren;
                }
            }
        }

        $monthlyTotalsForSync[SubsidyCodes::TREATMENT_IMPROVEMENT_CAT3] = $monthlyCat3;

        // --- 副食費徴収免除加算（pass-through：対象児数 × 単価）---
        $monthlyFoodFee = array_fill_keys($months, 0.0);
        $foodFeeUnit = (float) ($priceComponentsByItemAndAge[ 'HOIKUEN_23_FOOD_FEE_EXEMPTION_YEN']['ALL']['basic'] ?? 0);
        if ($foodFeeUnit > 0) {
            foreach ($months as $ym) {
                $targetCount = (float) ($addons[SubsidyCodes::FOOD_FEE_EXEMPTION][$ym]['input_value'] ?? 0);
                $monthlyFoodFee[$ym] = $targetCount * $foodFeeUnit;
            }
        }
        $monthlyTotalsForSync[SubsidyCodes::FOOD_FEE_EXEMPTION] = $monthlyFoodFee;

        return array_merge($monthlyTotalsForSync, $monthlyFixedAmountAddons, $monthlySelectAddons);
    }

    /**
     * 施設全体額を1人あたり単価に変換し、10円未満切り捨て後に園児数を掛けて戻す。
     * 公定価格の標準端数処理ルール（人あたり単価は10円未満切り捨て）に準拠。
     *
     * @return array{0: float, 1: float} [roundedBasic, roundedTi]
     */
    private function applyPerChildRounding(float $basicAmount, float $tiAmount, float $totalChildren): array
    {
        $combined = $basicAmount + $tiAmount;
        if ($combined <= 0 || $totalChildren <= 0) {
            return [0.0, 0.0];
        }

        $perChild = floor($combined / $totalChildren / 10) * 10;
        $roundedCombined = $perChild * $totalChildren;

        // 基本分とTIの比率で按分
        $roundedBasic = round($roundedCombined * $basicAmount / $combined);
        $roundedTi = $roundedCombined - $roundedBasic;

        return [$roundedBasic, $roundedTi];
    }

    private function calculateBasicAmount(float $count, float $basic): float
    {
        return $count * $basic;
    }

    private function calculateTi12Amount(
        float $count,
        float $ti,
        float $category1Percent,
        float $category2Percent,
        float $rateC
    ): float {
        // 処遇改善等加算Ⅰ(区分1,2)の1人あたり単価 ＝ 素単価ti × 合計率(区分1+区分2+率c) を10円未満切り捨て。
        // ★ category1/2Percent は「整数%(例:12,7)」で渡すこと。小数(0.12等)で渡すと約1/100になり7倍ほど過小になる。
        //   例(4歳標準): ti450 ×(12+7+3.0=22)=9,900円/人 → ×人数。floor(x/10)*10 は10円未満切り捨て。
        $perChildTi = floor($ti * ($category1Percent + $category2Percent + $rateC) / 10) * 10;

        return $count * $perChildTi;
    }

    /**
     * @return array<int, string>
     */
    private function officialPriceItemCodesForSync(): array
    {
        $class12ItemCodesByComponent = OfficialPriceItemCodeCatalog::class12ItemCodesByComponent();
        $class12ShortItemCodesByComponent = OfficialPriceItemCodeCatalog::class12ShortItemCodesByComponent();
        $teamCareItemCodesByComponent = OfficialPriceItemCodeCatalog::teamCareItemCodesByComponent();

        return array_values(array_unique(array_merge(
            array_values($class12ItemCodesByComponent),
            array_values($class12ShortItemCodesByComponent),
            array_values($teamCareItemCodesByComponent),
            $this->evidenceAgeSpecificAddonItemCodes(),
            $this->evidenceFixedAmountAddonItemCodes(),
            EvidenceAddonCatalog::selectAddonItemCodes(),
            [
                'HOIKUEN_23_TREATMENT_CAT3_PART1_YEN',
                'HOIKUEN_23_TREATMENT_CAT3_PART2_YEN',
                'HOIKUEN_23_FACILITY_CAPABILITY_STRENGTHENING_YEN',
                'HOIKUEN_23_NIGHT_CARE_ADDON_YEN',
                'HOIKUEN_23_NIGHT_CARE_TREATMENT_YEN',
                'HOIKUEN_23_NIGHT_CARE_RATE_C',
                'HOIKUEN_23_DIRECTOR_NOT_ASSIGNED_DEDUCTION_YEN',
                'HOIKUEN_23_DIRECTOR_NOT_ASSIGNED_TREATMENT_YEN',
                'HOIKUEN_23_DIRECTOR_NOT_ASSIGNED_RATE_C',
                'HOIKUEN_23_SATURDAY_CLOSURE_1DAY_RATE',
                'HOIKUEN_23_SATURDAY_CLOSURE_2DAYS_RATE',
                'HOIKUEN_23_SATURDAY_CLOSURE_3PLUS_RATE',
                'HOIKUEN_23_SATURDAY_CLOSURE_ALL_RATE',
                'HOIKUEN_23_BRANCH_FACILITY_RATE',
                'HOIKUEN_23_FOOD_FEE_EXEMPTION_YEN',
            ]
        )));
    }

    private function normalizeComponentKey(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));
        if (!in_array($normalized, ['basic', 'ti', 'c'], true)) {
            return null;
        }

        return $normalized;
    }

    /**
     * @param array<string, array<string, array<string, float>>> $componentsByItemAndAge
     * @param array<int, string> $itemCodes
     * @return array<string, array{basic?: float, ti?: float, c?: float}>
     */
    private function buildPriceComponentsByItemAndAge(array $componentsByItemAndAge, array $itemCodes): array
    {
        $componentsByAge = [];
        foreach ($itemCodes as $itemCode) {
            foreach (($componentsByItemAndAge[$itemCode] ?? []) as $ageCode => $components) {
                foreach (['basic', 'ti', 'c'] as $componentKey) {
                    if (array_key_exists($componentKey, $components)) {
                        $componentsByAge[$ageCode][$componentKey] = (float) $components[$componentKey];
                    }
                }
            }
        }

        return $componentsByAge;
    }

    /**
     * @param array{basic?: float, ti?: float, c?: float} $components
     */
    private function hasCommonComponents(array $components): bool
    {
        return array_key_exists('basic', $components)
            && array_key_exists('ti', $components)
            && array_key_exists('c', $components);
    }

    /**
     * @param array{basic?: float, ti?: float, c?: float} $components
     */
    private function hasTi12Components(array $components): bool
    {
        return array_key_exists('ti', $components) && array_key_exists('c', $components);
    }

    /**
     * @param array<string, array<string, int|string|float|null>> $inputs
     * @param array<int, string> $months
     * @return array<string, float>
     */
    private function buildMonthlyChildrenCounts(array $inputs, array $months): array
    {
        $monthlyChildrenCounts = array_fill_keys($months, 0.0);
        foreach (EvidenceInputCodeCatalog::codes() as $code) {
            foreach ($months as $ym) {
                $monthlyChildrenCounts[$ym] += (float) ($inputs[$code][$ym] ?? 0);
            }
        }

        return $monthlyChildrenCounts;
    }

    /**
     * @return array<int, string>
     */
    private function evidenceAgeSpecificAddonItemCodes(): array
    {
        $codes = [];
        foreach (EvidenceAddonCatalog::ageSpecificAddonRules() as $rule) {
            foreach ($rule['item_codes'] as $itemCode) {
                $normalized = trim((string) $itemCode);
                if ($normalized === '') {
                    continue;
                }

                $codes[$normalized] = $normalized;
            }
        }

        return array_values($codes);
    }

    /**
     * @return array<int, string>
     */
    private function evidenceFixedAmountAddonItemCodes(): array
    {
        $codes = [];
        foreach (EvidenceAddonCatalog::fixedAmountAddonDefinitions() as $definition) {
            foreach ([
                $definition['basic_item_code'] ?? null,
                $definition['ti_item_code'] ?? null,
                $definition['rate_c_item_code'] ?? null,
            ] as $itemCode) {
                $normalized = trim((string) $itemCode);
                if ($normalized === '') {
                    continue;
                }

                $codes[$normalized] = $normalized;
            }
        }

        return array_values($codes);
    }

    /**
     * @param array<int, string> $months
     * @return array<string, array<string, float>>
     */
    private function initializeFixedAmountAddonMonthlyTotals(array $months): array
    {
        $totals = [];
        foreach (EvidenceAddonCatalog::fixedAmountAddonDefinitions() as $definition) {
            $baseCode = $definition['subsidy_code'];
            $totals[$baseCode] = array_fill_keys($months, 0.0);
            if (($definition['ti_item_code'] ?? null) !== null && ($definition['rate_c_item_code'] ?? null) !== null) {
                $totals[SubsidyCodes::ti12Code($baseCode)] = array_fill_keys($months, 0.0);
            }
        }

        return $totals;
    }

    /**
     * 系統選択型加算の月次合計を初期化する。
     *
     * @param array<int, string> $months
     * @return array<string, array<string, float>>
     */
    private function initializeSelectAddonMonthlyTotals(array $months): array
    {
        $totals = [];
        foreach (EvidenceAddonCatalog::selectAddonDefinitions() as $uiCode => $definition) {
            $baseCode = $uiCode; // ui_code == base_code for select addons
            $totals[$baseCode] = array_fill_keys($months, 0.0);
            // TI コードはオプションのいずれかが item_code_ti を持つ場合に初期化
            $hasTi = false;
            foreach ($definition['options'] as $option) {
                if (($option['item_code_ti'] ?? null) !== null) {
                    $hasTi = true;
                    break;
                }
            }
            if ($hasTi) {
                $totals[SubsidyCodes::ti12Code($baseCode)] = array_fill_keys($months, 0.0);
            }
        }

        return $totals;
    }

    /**
     * @param array<string, array{basic?: float, ti?: float, c?: float}> $componentsByAge
     * @return array{basic?: float, ti?: float, c?: float}
     */
    private function resolveCommonPriceComponents(array $componentsByAge): array
    {
        if (isset($componentsByAge['ALL'])) {
            return $componentsByAge['ALL'];
        }

        foreach ($componentsByAge as $components) {
            if (is_array($components) && $components !== []) {
                return $components;
            }
        }

        return [];
    }
}
