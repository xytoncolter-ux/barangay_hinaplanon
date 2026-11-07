<?php
// src/Controllers/PreparednessController.php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use App\Models\PreparednessActivity;
use App\Models\Purok;
use App\Models\EventEvaluation;
use App\Models\PreparednessOverview;
use App\ML\PreparednessAnalysis;
use App\ML\ColabModelClient;
use App\ML\PreparednessQuantityPlan;
use App\Support\ActivityCalendar;

class PreparednessController {
    public function index(): void {
        $this->management('events');
    }

    private function management(string $defaultTab = 'activities', ?array $preview = null, ?string $previewError = null): void {
        Auth::requireLogin();
        if (!Auth::hasRole(['Resident','Responder','Barangay Head','ICDRRMO'])) return;
        $status = Request::get('status');
        if (!is_string($status)) $status = null;
        $filters = [];
        if (!empty($status)) {
            $filters['status'] = $status;
        }
        $inScope = static fn($a) => in_array(strtolower(trim($a['barangay_name'])), ['hinaplanon','barangay hinaplanon'], true);
        // Calendar filters operate on the complete authorized archive, not an SQL status subset.
        $isCalendar = Request::get('route') === 'preparedness-calendar' || Request::get('tab') === 'activities';
        $activities = array_values(array_filter(PreparednessActivity::all($isCalendar ? [] : $filters), $inScope));
        $activities = array_values(array_filter($activities, fn($a)=>ActivityCalendar::visibleToRole($a,(string)Auth::role())));
        $upcoming = array_values(array_filter(PreparednessActivity::getUpcoming(), $inScope));
        $past = array_values(array_filter(PreparednessActivity::getPast(), $inScope));
        $summary = ['total'=>count(array_unique(array_column($activities,'preparedness_activities_id'))),
            'upcoming'=>count($upcoming), 'completed'=>count(array_filter($activities,fn($a)=>$a['status']==='Completed')),
            'total_turnout'=>array_sum(array_column($activities,'actual_turnout'))];

        $role = Auth::role();
        $overview = PreparednessOverview::load((string)$role, (int)Auth::id());
        $tab = Request::get('tab', $defaultTab);
        if (!in_array($tab, ['events','analysis','allocations','relief','activities'], true)) $tab = $defaultTab;
        $analysis = $role === 'Resident' ? [] : PreparednessAnalysis::analyze($overview['puroks'], $overview['trustedHistory'], $overview['events'], $activities);
        $month = Request::get('month', date('Y-m'));
        if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');

        View::render('preparedness.management', [
            'title' => match ($role) {
                'ICDRRMO' => 'Event Logs',
                'Barangay Head', 'Responder' => 'Event Lists',
                default => 'Disaster Preparedness Management',
            },
            'overview' => $overview, 'analysis' => $analysis, 'activeTab' => $tab,
            'preview' => $preview, 'previewError' => $previewError,
            'selectedMonth' => $month,
            'activities' => $activities,
            'summary' => $summary,
            'upcoming' => $upcoming,
            'past' => $past,
            'statusFilter' => $status
        ]);
    }

    public function calendar(): void {
        $this->management();
    }

    public function predict(): void {
        Auth::requireRole(['Barangay Head','ICDRRMO']);
        if (!Auth::hasRole(['Barangay Head','ICDRRMO'])) return;
        $csrf = Request::post('csrf_token');
        if (!Request::isPost() || !is_string($csrf) || !Auth::validateCsrf($csrf)) {
            http_response_code(403);
            $this->management('analysis', null, 'Security verification failed. No prediction was requested.');
            return;
        }
        $id = filter_var(Request::post('disaster_request_id'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $data = PreparednessOverview::load((string)Auth::role(), (int)Auth::id());
        $matches = array_values(array_filter($data['events'], fn($r) => (int)$r['disaster_request_id'] === $id));
        if (!$id || !$matches) {
            http_response_code(422);
            $this->management('analysis', null, 'Select an existing verified Hinaplanon incident.');
            return;
        }
        $row = $matches[0];
        $features = array_intersect_key($row, array_flip(['disaster_type','severity_level','affected_households','affected_population']));
        $features['is_urgent'] = (int)$row['is_urgent'];
        $features['purok_risk_level'] = $row['risk_classification'];
        $prediction = ColabModelClient::predict($features);
        $quantityPlan = null;
        $supplyId = filter_var(Request::post('supply_id'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $rate = filter_var(Request::post('units_per_household'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>100]]);
        foreach ($data['inventory'] as $supply) {
            if ($supplyId === (int)$supply['supply_id'] && $rate !== false && $row['affected_households'] !== null
                && filter_var($row['affected_households'], FILTER_VALIDATE_INT) !== false
                && $row['affected_households'] >= 0 && $row['affected_households'] <= 100000000
                && filter_var($supply['supply_remaining'], FILTER_VALIDATE_INT) !== false && $supply['supply_remaining'] >= 0) {
                $quantityPlan = PreparednessQuantityPlan::calculate((int)$row['affected_households'], $rate, (int)$supply['supply_remaining']);
                $quantityPlan['supply_name'] = $supply['supply_name'];
                $quantityPlan['unit'] = $supply['unit_of_measure'];
            }
        }
        $this->management('analysis', ['result'=>$prediction,'features'=>$features,'request_id'=>$id,'quantity_plan'=>$quantityPlan],
            $prediction ? null : 'Experimental service disabled, unavailable, or returned an invalid result. No allocations or inventory were changed.');
    }

    public function create(): void {
        Auth::requireRole(['Barangay Head', 'ICDRRMO']);
        $puroks = Purok::all();

        if (Request::isPost()) {
            if (!Auth::validateCsrf(Request::post('csrf_token'))) {
                Auth::setFlash('danger', 'Security validation failed.');
                header("Location: index.php?route=preparedness-create");
                exit;
            }

            $type = Request::post('activity_type', 'Disaster Drill');
            $title = trim(Request::post('title', ''));
            $desc = trim(Request::post('description', ''));
            $purokId = Request::post('target_purok_id') ? (int)Request::post('target_purok_id') : null;
            $date = Request::post('schedule_date');
            $location = trim(Request::post('location', ''));
            $maxPart = (int)Request::post('max_participants', 100);
            $assignedPersonnel = trim(Request::post('assigned_personnel', ''));
            $volunteerPerson = trim(Request::post('volunteer_person', ''));

            if (empty($title) || empty($date) || empty($location)) {
                Auth::setFlash('warning', 'Please complete title, scheduled date/time, and venue location.');
                header("Location: index.php?route=preparedness-create");
                exit;
            }

            $actId = PreparednessActivity::create([
                'user_id' => (int)Auth::id(),
                'barangay_id' => 1,
                'activity_type' => $type,
                'title' => $title,
                'description' => $desc,
                'target_purok_id' => $purokId,
                'schedule_date' => $date,
                'location' => $location,
                'assigned_personnel' => $assignedPersonnel,
                'volunteer_person' => $volunteerPerson,
                'max_participants' => $maxPart
            ]);

            Auth::setFlash('success', "Preparedness activity '{$title}' scheduled successfully with assigned personnel and volunteers.");
            header("Location: index.php?route=preparedness-calendar");
            exit;
        }

        View::render('preparedness.create', [
            'title' => 'Schedule Preparedness Activity',
            'puroks' => $puroks
        ]);
    }

    public function evaluate(): void {
        Auth::requireRole(['Barangay Head', 'ICDRRMO', 'Responder']);
        $activityId = (int)Request::get('id');
        $activity = PreparednessActivity::findById($activityId);

        if (!$activity) {
            Auth::setFlash('danger', 'Activity not found.');
            header("Location: index.php?route=preparedness");
            exit;
        }

        if (Request::isPost()) {
            if (!Auth::validateCsrf(Request::post('csrf_token'))) {
                Auth::setFlash('danger', 'Security validation failed.');
                header("Location: index.php?route=preparedness-evaluate&id={$activityId}");
                exit;
            }

            $turnout = (int)Request::post('actual_turnout', 0);
            $rating = (int)Request::post('objectives_met_rating', 5);
            $problems = trim(Request::post('problems_encountered', ''));
            $supplies = trim(Request::post('supplies_used', ''));
            $recommendations = trim(Request::post('recommendations_for_improvement', ''));

            EventEvaluation::create([
                'preparedness_activities_id' => $activityId,
                'user_id' => (int)Auth::id(),
                'actual_turnout' => $turnout,
                'objectives_met_rating' => $rating,
                'problems_encountered' => $problems,
                'supplies_used' => $supplies,
                'recommendations_for_improvement' => $recommendations
            ]);

            // Mark activity completed
            PreparednessActivity::updateStatus($activityId, 'Completed');

            Auth::setFlash('success', 'Event evaluation recorded and activity marked as completed.');
            header("Location: index.php?route=preparedness");
            exit;
        }

        View::render('preparedness.evaluate', [
            'title' => 'Evaluate Activity: ' . $activity['title'],
            'activity' => $activity
        ]);
    }
}
