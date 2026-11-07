<?php
namespace App\ML;

/** Ephemeral, read-only preparedness outlook; never overwrites reviewed assessments. */
final class PreparednessAnalysis {
    public static function analyze(array $puroks, array $history, array $events, array $activities): array {
        $rows = [];
        foreach ($puroks as $p) {
            $past = array_values(array_filter($history, fn($h) => $h['affected_purok'] === $p['purok_name']));
            $current = array_values(array_filter($events, fn($e) => (int)$e['purok_id'] === (int)$p['purok_id']));
            $coverage = array_filter($activities, fn($a) => (int)($a['target_purok_id'] ?? 0) === (int)$p['purok_id']);
            $risk = $p['risk_classification'];
            $priority = match ($risk) { 'High' => 'High', 'Medium' => 'Medium', 'Low' => 'Low', default => 'Not Assessed' };
            $prediction = null;
            $source = $past[0] ?? null;
            // Archived verified observations are not treated as a current emergency.
            if ($source) {
                try {
                    $model = new DecisionTreeClassifier();
                    $model->loadFromJson(__DIR__ . '/../../ml/data/active_model.json');
                    $prediction = $model->predict(FeatureExtractor::extract($source));
                    $priority = $prediction['predicted_priority'];
                } catch (\Throwable $e) { /* Clearly labeled map-only baseline below. */ }
            }
            $rows[] = ['purok_id' => (int)$p['purok_id'], 'purok_name' => $p['purok_name'],
                'risk' => $risk, 'priority' => $priority, 'history_count' => count($past),
                'verified_request_count' => count($current), 'activity_count' => count($coverage),
                'prediction' => $prediction, 'source_record_id' => $source['record_id'] ?? null,
                'source_reference' => $p['hazard_source_reference'] ?? null,
                'explanation' => ($prediction ? 'Experimental Decision Tree using historical record #' . $source['record_id'] : 'Map-only planning baseline; insufficient reconciled historical evidence for ML impact analysis.')
                    . ' No current impact is inferred. ' . (count($coverage) ? 'Review existing activity coverage before scheduling.' : 'No explicitly targeted activity recorded; review training needs (barangay-wide activities may apply).')];
        }
        $order = ['Urgent'=>0,'Critical'=>0,'High'=>1,'Medium'=>2,'Moderate'=>2,'Low'=>3,'Not Assessed'=>4];
        usort($rows, fn($a,$b) => ($order[$a['priority']] ?? 4) <=> ($order[$b['priority']] ?? 4) ?: $a['purok_id'] <=> $b['purok_id']);
        return $rows;
    }
}
