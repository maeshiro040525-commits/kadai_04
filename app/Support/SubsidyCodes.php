<?php

namespace App\Support;

final class SubsidyCodes
{
    public const TI12_CODE_SUFFIX = '_TI1_TI2';

    public const BASIC_UNIT_PRICE = 'BASIC_UNIT_PRICE';
    public const BASIC_UNIT_PRICE_TI12 = 'BASIC_UNIT_PRICE_TI1_TI2';
    public const TEAM_CARE = 'TEAM_CARE';
    public const TEAM_CARE_TI12 = 'TEAM_CARE_TI1_TI2';
    public const AGE4 = 'AGE4';
    public const AGE4_TI12 = 'AGE4_TI1_TI2';
    public const AGE3 = 'AGE3';
    public const AGE3_TI12 = 'AGE3_TI1_TI2';
    public const AGE1 = 'AGE1';
    public const AGE1_TI12 = 'AGE1_TI1_TI2';
    public const CHIEF_NURSERY_TEACHER_DEDICATED = 'CHIEF_NURSERY_TEACHER_DEDICATED';
    public const CHIEF_NURSERY_TEACHER_DEDICATED_TI12 = 'CHIEF_NURSERY_TEACHER_DEDICATED_TI1_TI2';
    public const ADMIN_STAFF_HIRING = 'ADMIN_STAFF_HIRING';
    public const ADMIN_STAFF_HIRING_TI12 = 'ADMIN_STAFF_HIRING_TI1_TI2';
    public const HEATING_COOLING = 'HEATING_COOLING';
    public const THIRD_PARTY_EVALUATION = 'THIRD_PARTY_EVALUATION';
    public const CAP23_MIN_STAFFING_ASSIGNMENT = 'CAP23_MIN_STAFFING_ASSIGNMENT';

    // 副食費徴収免除加算（pass-through：収入として記録するがKPIの分母からは除外）
    public const FOOD_FEE_EXEMPTION = 'FOOD_FEE_EXEMPTION';

    // 系統選択型加算（tier-selection addons）— base_code
    public const NUTRITION_MANAGEMENT = 'NUTRITION_MANAGEMENT';
    public const THERAPEUTIC_SUPPORT = 'THERAPEUTIC_SUPPORT';
    public const SENIOR_ACTIVITY_PROMOTION = 'SENIOR_ACTIVITY_PROMOTION';
    public const SCHOOL_TRANSITION = 'SCHOOL_TRANSITION';

    // 系統選択型加算 — 個別ティアコード
    public const NUTRITION_MANAGEMENT_A = 'NUTRITION_MANAGEMENT_A';
    public const NUTRITION_MANAGEMENT_B = 'NUTRITION_MANAGEMENT_B';
    public const NUTRITION_MANAGEMENT_C = 'NUTRITION_MANAGEMENT_C';
    public const NUTRITION_MANAGEMENT_TI12 = 'NUTRITION_MANAGEMENT_TI1_TI2';
    public const THERAPEUTIC_SUPPORT_A = 'THERAPEUTIC_SUPPORT_A';
    public const THERAPEUTIC_SUPPORT_B = 'THERAPEUTIC_SUPPORT_B';
    public const THERAPEUTIC_SUPPORT_TI12 = 'THERAPEUTIC_SUPPORT_TI1_TI2';
    public const SENIOR_ACTIVITY_PROMOTION_TIER1 = 'SENIOR_ACTIVITY_PROMOTION_TIER1';
    public const SENIOR_ACTIVITY_PROMOTION_TIER2 = 'SENIOR_ACTIVITY_PROMOTION_TIER2';
    public const SENIOR_ACTIVITY_PROMOTION_TIER3 = 'SENIOR_ACTIVITY_PROMOTION_TIER3';
    public const SCHOOL_TRANSITION_REQ123 = 'SCHOOL_TRANSITION_REQ123';
    public const SCHOOL_TRANSITION_REQ12 = 'SCHOOL_TRANSITION_REQ12';

    // 処遇改善等加算（区分3）
    public const TREATMENT_IMPROVEMENT_CAT3 = 'TREATMENT_IMPROVEMENT_CAT3';

    // 施設機能強化推進費
    public const FACILITY_CAPABILITY_STRENGTHENING = 'FACILITY_CAPABILITY_STRENGTHENING';

    // 減価償却費加算（標準/都市部）
    public const DEPRECIATION = 'DEPRECIATION';
    public const DEPRECIATION_STANDARD = 'DEPRECIATION_STANDARD';
    public const DEPRECIATION_URBAN = 'DEPRECIATION_URBAN';

    // 賃借料加算（標準/都市部）
    public const RENT = 'RENT';
    public const RENT_STANDARD = 'RENT_STANDARD';
    public const RENT_URBAN = 'RENT_URBAN';

    // 除雪費加算・降灰除去費加算
    public const SNOW_REMOVAL = 'SNOW_REMOVAL';
    public const ASH_REMOVAL = 'ASH_REMOVAL';

    // 休日保育加算（select型・14段階）
    public const HOLIDAY_CARE = 'HOLIDAY_CARE';
    public const HOLIDAY_CARE_TIER_1 = 'HOLIDAY_CARE_TIER_1';
    public const HOLIDAY_CARE_TIER_2 = 'HOLIDAY_CARE_TIER_2';
    public const HOLIDAY_CARE_TIER_3 = 'HOLIDAY_CARE_TIER_3';
    public const HOLIDAY_CARE_TIER_4 = 'HOLIDAY_CARE_TIER_4';
    public const HOLIDAY_CARE_TIER_5 = 'HOLIDAY_CARE_TIER_5';
    public const HOLIDAY_CARE_TIER_6 = 'HOLIDAY_CARE_TIER_6';
    public const HOLIDAY_CARE_TIER_7 = 'HOLIDAY_CARE_TIER_7';
    public const HOLIDAY_CARE_TIER_8 = 'HOLIDAY_CARE_TIER_8';
    public const HOLIDAY_CARE_TIER_9 = 'HOLIDAY_CARE_TIER_9';
    public const HOLIDAY_CARE_TIER_10 = 'HOLIDAY_CARE_TIER_10';
    public const HOLIDAY_CARE_TIER_11 = 'HOLIDAY_CARE_TIER_11';
    public const HOLIDAY_CARE_TIER_12 = 'HOLIDAY_CARE_TIER_12';
    public const HOLIDAY_CARE_TIER_13 = 'HOLIDAY_CARE_TIER_13';
    public const HOLIDAY_CARE_TIER_14 = 'HOLIDAY_CARE_TIER_14';

    // 夜間保育加算
    public const NIGHT_CARE = 'NIGHT_CARE';

    // 調整部分（減額）
    public const BRANCH_FACILITY = 'BRANCH_FACILITY';
    public const DIRECTOR_NOT_ASSIGNED = 'DIRECTOR_NOT_ASSIGNED';
    public const DIRECTOR_NOT_ASSIGNED_TI12 = 'DIRECTOR_NOT_ASSIGNED_TI1_TI2';
    public const SATURDAY_CLOSURE = 'SATURDAY_CLOSURE';
    public const SATURDAY_CLOSURE_1DAY = 'SATURDAY_CLOSURE_1DAY';
    public const SATURDAY_CLOSURE_2DAYS = 'SATURDAY_CLOSURE_2DAYS';
    public const SATURDAY_CLOSURE_3PLUS_DAYS = 'SATURDAY_CLOSURE_3PLUS_DAYS';
    public const SATURDAY_CLOSURE_ALL = 'SATURDAY_CLOSURE_ALL';
    public const CHRONIC_OVER_CAPACITY = 'CHRONIC_OVER_CAPACITY';

    public const INPUT_LINKED_BASE_CODES = [
        self::BASIC_UNIT_PRICE,
        self::TEAM_CARE,
        self::AGE4,
        self::AGE3,
        self::AGE1,
    ];

    public const INPUT_LINKED_CODES = [
        self::BASIC_UNIT_PRICE,
        self::BASIC_UNIT_PRICE_TI12,
        self::TEAM_CARE,
        self::TEAM_CARE_TI12,
        self::AGE4,
        self::AGE4_TI12,
        self::AGE3,
        self::AGE3_TI12,
        self::AGE1,
        self::AGE1_TI12,
    ];

    // pass-through加算：収入としては記録するが、人件費割合などKPIの分母からは除外するコード群。
    // （例：副食費徴収免除加算＝保護者から徴収しない副食費を行政が補填するだけで、人件費・配置には影響しない収入）
    public const PASS_THROUGH_CODES = [
        self::FOOD_FEE_EXEMPTION,
    ];

    public static function isTi12Code(string $code): bool
    {
        return str_ends_with($code, self::TI12_CODE_SUFFIX);
    }

    public static function resolveBaseCode(string $code): string
    {
        if (!self::isTi12Code($code)) {
            return $code;
        }

        return substr($code, 0, -strlen(self::TI12_CODE_SUFFIX));
    }

    public static function ti12Code(string $baseCode): string
    {
        return self::resolveBaseCode($baseCode).self::TI12_CODE_SUFFIX;
    }
}
