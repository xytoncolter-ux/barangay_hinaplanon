<?php
// src/Controllers/RecommendationController.php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\View;
use App\Models\Recommendation;
use App\Models\PurokAssessment;
use App\ML\PurokAssessmentEngine;
use PDO;

class RecommendationController {
    public function index(): void {
        Auth::requireRole(['ICDRRMO', 'Barangay Head', 'Responder']);
        $statusFilter = Request::get('status', '');
        $priorityFilter = Request::get('priority', '');
        $tab = Request::get('tab', 'puroks'); // 'puroks' or 'incidents'

        $filters = [];
        if (!empty($statusFilter)) $filters['status'] = $statusFilter;
        if (!empty($priorityFilter)) $filters['priority'] = $priorityFilter;

        // 1. Incident-Level Recommendations (Legacy & Active Incident Requests)
        $recommendations = Recommendation::all($filters);

        // 2. Purok-Level ML & XAI Allocation Assessments
        $purokFilters = [];
        if (!empty($priorityFilter)) $purokFilters['priority'] = $priorityFilter;
        if (!empty($statusFilter)) $purokFilters['status'] = $statusFilter;

        $purokAssessments = PurokAssessment::all($purokFilters);
        // A filtered/empty GET must never delete and regenerate reviewed assessments.

        $kpiSummary = PurokAssessment::getKpiSummary();

        // 3. Active ML Model Metadata
        $db = Database::getInstance();
        $activeModel = $db->query("
            SELECT * FROM model_versions 
            WHERE is_active = 1 
            ORDER BY model_version_id DESC LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);

        View::render('recommendations.index', [
            'title' => 'AI Priority Recommendations & Purok Relief Allocation',
            'recommendations' => $recommendations,
            'purokAssessments' => $purokAssessments,
            'kpiSummary' => $kpiSummary,
            'activeModel' => $activeModel,
            'statusFilter' => $statusFilter,
            'priorityFilter' => $priorityFilter,
            'currentTab' => $tab,
            'currentRole' => (string)Auth::role()
        ]);
    }

    public function show(): void {
        Auth::requireRole(['ICDRRMO', 'Barangay Head', 'Responder']);
        $id = (int)Request::get('id');
        $rec = Recommendation::findById($id);

        if (!$rec) {
            Auth::setFlash('danger', 'Recommendation not found.');
            header("Location: index.php?route=recommendations");
            exit;
        }

        $explanation = json_decode($rec['explanation_details'] ?? '{}', true);

        View::render('recommendations.show', [
            'title' => 'Recommendation #' . $id,
            'rec' => $rec,
            'explanation' => $explanation
        ]);
    }

    public function purokShow(): void {
        Auth::requireRole(['ICDRRMO', 'Barangay Head', 'Responder']);
        $id = (int)Request::get('id');
        $assessment = PurokAssessment::findById($id);

        if (!$assessment) {
            Auth::setFlash('danger', 'Purok assessment not found.');
            header("Location: index.php?route=recommendations");
            exit;
        }

        $breakdown = json_decode($assessment['factor_breakdown'] ?? '[]', true);

        View::render('recommendations.purok_show', [
            'title' => 'Purok Allocation Assessment: ' . $assessment['purok_name'],
            'assessment' => $assessment,
            'breakdown' => $breakdown,
            'currentRole' => (string)Auth::role()
        ]);
    }

    public function review(): void {
        Auth::requireRole(['ICDRRMO', 'Barangay Head']);
        if (!Auth::validateCsrf(Request::post('csrf_token'))) {
            Auth::setFlash('danger', 'Security validation failed.');
            header("Location: index.php?route=recommendations");
            exit;
        }

        $id = (int)Request::post('recommendation_id');
        $action = Request::post('action'); // 'approve', 'modify', 'reject'
        $justification = trim(Request::post('justification', ''));
        $modQty = Request::post('modified_quantity') ? (int)Request::post('modified_quantity') : null;

        if (empty($justification)) {
            Auth::setFlash('warning', 'A written justification is required for all official review actions.');
            header("Location: index.php?route=recommendations-show&id={$id}");
            exit;
        }

        $res = Recommendation::review($id, $action, $justification, (int)Auth::id(), $modQty);

        if ($res['success']) {
            Auth::setFlash('success', "Recommendation updated: {$res['status']} successfully. Centralized inventory and audit trail updated.");
        } else {
            Auth::setFlash('danger', 'Action failed: ' . $res['message']);
        }

        header("Location: index.php?route=recommendations-show&id={$id}");
        exit;
    }

    public function purokReview(): void {
        Auth::requireRole(['ICDRRMO', 'Barangay Head']);
        if (!Auth::validateCsrf(Request::post('csrf_token'))) {
            Auth::setFlash('danger', 'Security validation failed.');
            header("Location: index.php?route=recommendations");
            exit;
        }

        $assessmentId = (int)Request::post('assessment_id');
        $action = Request::post('action'); // 'approve', 'modify', 'reject'
        $justification = trim(Request::post('justification', ''));

        $modRice = Request::post('modified_rice_kg') !== null && Request::post('modified_rice_kg') !== '' ? (int)Request::post('modified_rice_kg') : null;
        $modCanned = Request::post('modified_canned_tins') !== null && Request::post('modified_canned_tins') !== '' ? (int)Request::post('modified_canned_tins') : null;
        $modWater = Request::post('modified_water_bottles') !== null && Request::post('modified_water_bottles') !== '' ? (int)Request::post('modified_water_bottles') : null;
        $modHygiene = Request::post('modified_hygiene_kits') !== null && Request::post('modified_hygiene_kits') !== '' ? (int)Request::post('modified_hygiene_kits') : null;
        $modBlankets = Request::post('modified_blankets') !== null && Request::post('modified_blankets') !== '' ? (int)Request::post('modified_blankets') : null;

        if (empty($justification)) {
            Auth::setFlash('warning', 'A written justification is required for all official review and approval actions.');
            header("Location: index.php?route=recommendations-purok-show&id={$assessmentId}");
            exit;
        }

        $res = PurokAssessment::review(
            $assessmentId,
            $action,
            $justification,
            (int)Auth::id(),
            $modRice,
            $modCanned,
            $modWater,
            $modHygiene,
            $modBlankets
        );

        if ($res['success']) {
            Auth::setFlash('success', "Purok allocation successfully updated to '{$res['status']}'.");
        } else {
            Auth::setFlash('danger', 'Review action failed: ' . $res['message']);
        }

        header("Location: index.php?route=recommendations-purok-show&id={$assessmentId}");
        exit;
    }

    public function recalculate(): void {
        Auth::requireRole(['ICDRRMO', 'Barangay Head']);
        if (!Auth::validateCsrf(Request::post('csrf_token'))) {
            Auth::setFlash('danger', 'Security validation failed.');
            header("Location: index.php?route=recommendations");
            exit;
        }

        $forceRule = (bool)Request::post('force_rule', false);
        $results = PurokAssessmentEngine::runAssessment($forceRule);

        $methodLabel = $forceRule ? 'Configurable Rule-Based Prioritization Engine' : 'Machine Learning (Random Forest Ensemble)';
        Auth::setFlash('success', "Successfully recalculated assessments across all " . count($results) . " puroks using {$methodLabel}.");
        header("Location: index.php?route=recommendations");
        exit;
    }

    public function exportPurokAllocations(): void {
        Auth::requireRole(['ICDRRMO', 'Barangay Head', 'Responder']);
        $assessments = PurokAssessment::all();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=Hinaplanon_Purok_Relief_Allocations_' . date('Ymd_His') . '.csv');

        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'Priority Rank', 'Purok Name', 'Hazard Exposure', 'Risk Color',
            'Priority Level', 'Composite Score', 'Affected Households', 'Affected Population',
            'Totally Damaged Houses', 'Partially Damaged Houses',
            'Recommended Rice (kg)', 'Recommended Canned Goods (tins)',
            'Recommended Water (units)', 'Recommended Hygiene Kits',
            'Recommended Blankets', 'Allocation Status', 'Stock Capped',
            'Assessment Method', 'Data Completeness', 'Evacuation Haven'
        ]);

        foreach ($assessments as $a) {
            fputcsv($out, [
                $a['priority_rank'],
                $a['purok_name'],
                $a['flood_hazard_exposure'],
                $a['risk_zone_color'],
                $a['priority_level'],
                $a['composite_risk_score'],
                $a['affected_households'],
                $a['affected_population'],
                $a['totally_damaged_houses'],
                $a['partially_damaged_houses'],
                $a['recommended_rice_kg'],
                $a['recommended_canned_tins'],
                $a['recommended_water_bottles'],
                $a['recommended_hygiene_kits'],
                $a['recommended_blankets'],
                $a['allocation_status'],
                $a['stock_capped'] ? 'Yes' : 'No',
                $a['assessment_method'],
                $a['data_completeness'],
                $a['evacuation_center']
            ]);
        }
        fclose($out);
        exit;
    }
}
