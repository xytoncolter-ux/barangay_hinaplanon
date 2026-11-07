<?php
namespace App\ML;

/** A reviewer-selected planning policy, not a trained quantity prediction. */
final class PreparednessQuantityPlan {
    public static function calculate(int $households, int $unitsPerHousehold, int $remaining): array {
        if ($households < 0 || $households > 100000000 || $unitsPerHousehold < 1 || $unitsPerHousehold > 100 || $remaining < 0) {
            throw new \InvalidArgumentException('Invalid household, policy rate or remaining stock');
        }
        $need = $households * $unitsPerHousehold;
        return ['demand'=>$need, 'proposed'=>min($need,$remaining), 'unmet'=>max(0,$need-$remaining),
            'remaining_after_preview'=>max(0,$remaining-$need), 'units_per_household'=>$unitsPerHousehold];
    }
}
