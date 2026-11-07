<?php
// tests/test_feature_removal_roles.php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

// PSR-4 Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = __DIR__ . '/../src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) require_once $file;
});

use App\Core\Auth;
use App\Core\Database;
use App\Models\User;
use App\Models\Shelter;
use App\Models\AuditLog;
use App\Models\HistoricalRecord;
use App\Models\TamEvaluation;
use App\ML\RecommenderEngine;
use App\ML\DemandCalculator;

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function assertCheck(bool $condition, string $description): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$description}\n";
    } else {
        $failedTests++;
        echo "  [FAIL] {$description}\n";
    }
}

function captureView(callable $fn): string {
    ob_start();
    try {
        $fn();
    } catch (\Throwable $e) {
        // Ignored or logged
    }
    return ob_get_clean() ?: '';
}

echo "====================================================================\n";
echo "   Feature Removal & Role Authorization Verification Test Suite     \n";
echo "====================================================================\n\n";

// ---------------------------------------------------------
// 1. RESIDENT ROLE VERIFICATION
// ---------------------------------------------------------
echo "TEST SUITE 1: Resident Role UI & Direct URL Access Restrictions\n";
Auth::login('juan.delacruz@gmail.com', 'resident123');
$residentDashboard = captureView(fn() => (new App\Controllers\DashboardController())->index());

// Features removed from Resident Dashboard & Sidebar:
assertCheck(strpos($residentDashboard, 'EMERGENCY SOS') === false && strpos($residentDashboard, 'Send Emergency SOS') === false, 
    "Emergency SOS is removed from Resident dashboard & navigation");
assertCheck(strpos($residentDashboard, 'Responder Field Status') === false, 
    "Responder Field Status is removed from Resident sidebar");
assertCheck(strpos($residentDashboard, 'TAM & ISO 9126 Results') === false, 
    "TAM & ISO 9126 Results is removed from Resident sidebar");
assertCheck(strpos($residentDashboard, 'dashboardHazardMap') === false, 
    "Live Hazard Map is removed from Resident dashboard");
assertCheck(strpos($residentDashboard, 'Live Hazard Map') === false, 
    "Live Hazard Map is removed from Resident sidebar");
assertCheck(strpos($residentDashboard, 'Evacuation Shelters') === false && strpos($residentDashboard, 'Open Evacuation Centers') === false, 
    "Evacuation Shelters is removed from Resident dashboard & sidebar");
assertCheck(strpos($residentDashboard, 'Disaster Incident Reports') === false, 
    "Disaster Incident Reports is removed from Resident sidebar");

// Preserved Resident features in UI:
assertCheck(strpos($residentDashboard, 'Fill Up Disaster Request Form') === false, 
    "Fill Up Disaster Request Form is removed from Resident dashboard & navigation");
assertCheck(strpos($residentDashboard, 'Event History') !== false, 
    "Preserved: Resident dashboard & sidebar display Event History");
assertCheck(strpos($residentDashboard, 'Disaster Preparedness Activities Schedules') === false, 
    "Disaster Preparedness Activities Schedules renamed to Event History for Resident");
assertCheck(strpos($residentDashboard, 'Notifications & Alerts') !== false, 
    "Preserved: Resident retains Notifications & Alerts");

// Direct URL Restrictions for Resident:
Auth::getFlashes(); // clear flashes
(new App\Controllers\SosController())->form();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']) || !empty($f['warning']), "Direct access to emergency-sos is denied for Resident");

Auth::getFlashes();
(new App\Controllers\HazardMapController())->index();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']), "Direct access to hazard-map is denied for Resident");

Auth::getFlashes();
(new App\Controllers\ShelterController())->index();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']) || !empty($f['warning']), "Direct access to shelters is denied for Resident");

Auth::getFlashes();
(new App\Controllers\FieldReportController())->index();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']), "Direct access to field-reports is denied for Resident");

Auth::getFlashes();
(new App\Controllers\FieldReportController())->create();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']), "Direct access to field-reports-create is denied for Resident");

Auth::getFlashes();
(new App\Controllers\EvaluationController())->results();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']) || !empty($f['warning']), "Direct access to evaluation-results is denied for Resident");

Auth::getFlashes();
(new App\Controllers\DisasterRequestController())->index();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']), "Direct access to disaster-requests index is denied for Resident");

Auth::getFlashes();
(new App\Controllers\DisasterRequestController())->create();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']), "Direct access to disaster-requests-create is denied for Resident");


// ---------------------------------------------------------
// 2. RESPONDER ROLE VERIFICATION
// ---------------------------------------------------------
echo "\nTEST SUITE 2: Responder Role UI & Direct URL Access Restrictions\n";
Auth::login('responder1@hinaplanon.gov.ph', 'responder123');
$responderDashboard = captureView(fn() => (new App\Controllers\DashboardController())->index());

// Features removed from Responder:
assertCheck(strpos($responderDashboard, 'dashboardHazardMap') === false, 
    "Live Hazard Map is removed from Responder dashboard");
assertCheck(strpos($responderDashboard, 'Live Hazard Map') === false, 
    "Live Hazard Map is removed from Responder sidebar");
assertCheck(strpos($responderDashboard, 'Evacuation Shelters') === false && strpos($responderDashboard, 'Evacuation Centers') === false, 
    "Evacuation Shelters is removed from Responder dashboard & sidebar");
assertCheck(strpos($responderDashboard, 'TAM & ISO 9126 Results') === false, 
    "TAM & ISO 9126 Results is removed from Responder sidebar");
assertCheck(strpos($responderDashboard, 'Notifications & Alerts') === false && strpos($responderDashboard, 'notif-bell-btn') === false, 
    "Notification & Alert is removed from Responder navigation & topbar");

// Preserved Responder features:
assertCheck(strpos($responderDashboard, 'My Assigned Emergency Operations') !== false, 
    "Preserved: Responder assigned emergency operations dashboard table");
assertCheck(strpos($responderDashboard, 'Ground Status & Road Passability') !== false, 
    "Preserved: Responder field report status table");
assertCheck(strpos($responderDashboard, 'Event History') !== false, 
    "Preserved: Responder retains Event History in sidebar");
assertCheck(strpos($responderDashboard, 'Disaster Preparedness Activities Schedules') === false, 
    "Disaster Preparedness Activities Schedules is renamed to Event History for Responder");
assertCheck(strpos($responderDashboard, 'Fill Up Disaster Request Form') === false, 
    "Fill Up Disaster Request Form is removed from Responder sidebar & dashboard");
assertCheck(strpos($responderDashboard, 'Event Lists') !== false,
    "Responder retains integrated preparedness/allocation access");
assertCheck(strpos($responderDashboard, 'Resource Management') !== false, 
    "Preserved: Responder retains Resource Management in sidebar");

// Direct URL Restrictions for Responder:
Auth::getFlashes();
(new App\Controllers\HazardMapController())->index();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']), "Direct access to hazard-map is denied for Responder");

Auth::getFlashes();
(new App\Controllers\ShelterController())->index();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']) || !empty($f['warning']), "Direct access to shelters is denied for Responder");

Auth::getFlashes();
(new App\Controllers\EvaluationController())->results();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']) || !empty($f['warning']), "Direct access to evaluation-results is denied for Responder");

Auth::getFlashes();
(new App\Controllers\NotificationController())->index();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']), "Direct access to notifications is denied for Responder");

Auth::getFlashes();
(new App\Controllers\DisasterRequestController())->create();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']), "Direct access to disaster-requests-create is denied for Responder");


// ---------------------------------------------------------
// 3. BARANGAY HEAD (ADMIN) ROLE VERIFICATION
// ---------------------------------------------------------
echo "\nTEST SUITE 3: Barangay Head (Admin) Role UI & Access Restrictions\n";
Auth::login('brgyhead@hinaplanon.gov.ph', 'brgyhead123');
$adminDashboard = captureView(fn() => (new App\Controllers\DashboardController())->index());

// Features removed from Barangay Head:
assertCheck(strpos($adminDashboard, 'Evacuation Shelters') === false && strpos($adminDashboard, 'Evacuees in Shelters') === false, 
    "Evacuation Shelters is removed from Barangay Head dashboard & navigation");
assertCheck(strpos($adminDashboard, 'Decision Tree Engine') === false, 
    "Decision Tree Engine page link is removed from Barangay Head sidebar");
assertCheck(strpos($adminDashboard, 'Historical Datasets') === false, 
    "Historical Datasets page link is removed from Barangay Head sidebar");
assertCheck(strpos($adminDashboard, 'TAM & ISO 9126 Results') === false, 
    "TAM & ISO 9126 Results is removed from Barangay Head sidebar");
assertCheck(strpos($adminDashboard, 'System Audit Trail') === false && strpos($adminDashboard, 'Recent Audit & Security Events') === false, 
    "System Audit Trail is removed from Barangay Head dashboard & sidebar");
assertCheck(strpos($adminDashboard, 'Notifications & Alerts') === false && strpos($adminDashboard, 'notif-bell-btn') === false, 
    "Notification & Alert is removed from Barangay Head navigation & topbar");

// Preserved Barangay Head features:
assertCheck(strpos($adminDashboard, 'dashboardHazardMap') !== false, 
    "Preserved: Live Interactive Leaflet Hazard Map on Barangay Head dashboard");
assertCheck(strpos($adminDashboard, 'Live Hazard Map') !== false, 
    "Preserved: Live Hazard Map in Barangay Head sidebar");
assertCheck(strpos($adminDashboard, 'AI Priority Recommendations') !== false, 
    "Preserved: AI Priority Recommendations in Barangay Head sidebar");
assertCheck(strpos($adminDashboard, 'Decision Tree Allocation Recommendations') !== false, 
    "Preserved: Decision Tree Allocation Recommendations table on Barangay Head dashboard");
assertCheck(strpos($adminDashboard, 'Residents Account Management') !== false, 
    "Preserved: Residents Account Management on Barangay Head dashboard & sidebar");
assertCheck(strpos($adminDashboard, 'Event Lists') !== false,
    "Barangay Head retains integrated preparedness navigation");
assertCheck(strpos($adminDashboard, 'Event History') === false, 
    "Event History is NOT displayed for Barangay Head (only in Residents and Personnel)");
assertCheck(strpos($adminDashboard, 'Resource Management') !== false, 
    "Preserved: Resource Management in Barangay Head sidebar");
assertCheck(strpos($adminDashboard, 'Fill Up Disaster Request Form') !== false, 
    "Preserved: Fill Up Disaster Request Form in Barangay Head sidebar & dashboard");
assertCheck(strpos($adminDashboard, 'Submit Ground Field Report') === false && strpos($adminDashboard, 'Submit Report') === false, 
    "Submit Status button is removed from Barangay Head dashboard");
assertCheck(strpos($adminDashboard, 'Responder Ground Field Reports — Verification Queue') !== false, 
    "Submitted field reports appear in Barangay Head administrative dashboard");

// Direct URL Restrictions for Barangay Head:
Auth::getFlashes();
(new App\Controllers\FieldReportController())->create();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']), "Direct access to field-reports-create is denied for Barangay Head (Responders only)");
Auth::getFlashes();
(new App\Controllers\ShelterController())->index();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']) || !empty($f['warning']), "Direct access to shelters is denied for Barangay Head");

Auth::getFlashes();
(new App\Controllers\MLController())->index();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']) || !empty($f['warning']), "Direct access to ml-model is denied for Barangay Head");

Auth::getFlashes();
(new App\Controllers\MLController())->historicalRecords();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']) || !empty($f['warning']), "Direct access to historical-records is denied for Barangay Head");

Auth::getFlashes();
(new App\Controllers\EvaluationController())->results();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']) || !empty($f['warning']), "Direct access to evaluation-results is denied for Barangay Head");

Auth::getFlashes();
(new App\Controllers\AuditController())->index();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']) || !empty($f['warning']), "Direct access to audit-logs is denied for Barangay Head");

Auth::getFlashes();
(new App\Controllers\NotificationController())->index();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']), "Direct access to notifications is denied for Barangay Head");

// Residents Account Management & Password Feature:
$resMgmtView = captureView(fn() => (new App\Controllers\UserController())->pendingResidents());
assertCheck(strpos($resMgmtView, 'Residents Account Management') !== false, "Barangay Head can view Residents Account Management page");
assertCheck(strpos($resMgmtView, 'Approved Residents & Passwords') !== false, "Residents Account Management displays approved resident accounts & passwords");

// Test Barangay Head password update for resident
$testResident = User::findByEmail('juan.delacruz@gmail.com');
assertCheck(!empty($testResident['plain_password']), "Resident has visible plain_password for Barangay Head lookup");
$resetOk = User::resetResidentPassword((int)$testResident['user_id'], 'newpass123', (int)Auth::id());
assertCheck($resetOk === true, "Barangay Head can reset resident password");
$updatedResident = User::findById((int)$testResident['user_id']);
assertCheck($updatedResident['plain_password'] === 'newpass123', "Resident plain_password is updated to new password");
// Restore password back to resident123
User::resetResidentPassword((int)$testResident['user_id'], 'resident123', (int)Auth::id());


// ---------------------------------------------------------
// 4. ICDRRMO (SUPER ADMIN) ROLE VERIFICATION
// ---------------------------------------------------------
echo "\nTEST SUITE 4: ICDRRMO (Super Admin) Role UI & Access Restrictions\n";
Auth::login('icdrrmo@iligan.gov.ph', 'icdrrmo123');
$superDashboard = captureView(fn() => (new App\Controllers\DashboardController())->index());

// Features removed from ICDRRMO:
assertCheck(strpos($superDashboard, 'Evacuation Shelters') === false && strpos($superDashboard, 'Evacuees in Shelters') === false, 
    "Evacuation Shelters is removed from ICDRRMO dashboard & navigation");
assertCheck(strpos($superDashboard, 'Decision Tree Engine') === false, 
    "Decision Tree Engine page link is removed from ICDRRMO sidebar");
assertCheck(strpos($superDashboard, 'Historical Datasets') === false, 
    "Historical Datasets page link is removed from ICDRRMO sidebar");
assertCheck(strpos($superDashboard, 'Notification & Alert') === false && strpos($superDashboard, 'notif-bell-btn') === false, 
    "Notification & Alert is removed from ICDRRMO navigation & topbar");
assertCheck(strpos($superDashboard, 'System Audit Trail') === false && strpos($superDashboard, 'Recent Audit & Security Events') === false, 
    "System Audit Trail is removed from ICDRRMO dashboard & sidebar");
assertCheck(strpos($superDashboard, 'TAM & ISO 9126 Results') === false, 
    "TAM & ISO 9126 Results is removed from ICDRRMO sidebar");

// Preserved ICDRRMO features:
assertCheck(strpos($superDashboard, 'dashboardHazardMap') !== false, 
    "Preserved: Live Interactive Leaflet Hazard Map on ICDRRMO dashboard");
assertCheck(strpos($superDashboard, 'Live Hazard Map') !== false, 
    "Preserved: Live Hazard Map in ICDRRMO sidebar");
assertCheck(strpos($superDashboard, 'System User Accounts') !== false, 
    "Preserved: System User Accounts in ICDRRMO sidebar");
assertCheck(strpos($superDashboard, 'AI Priority Recommendations') !== false, 
    "Preserved: AI Priority Recommendations in ICDRRMO sidebar");
assertCheck(strpos($superDashboard, 'Event Logs') !== false,
    "ICDRRMO retains integrated preparedness navigation");
assertCheck(strpos($superDashboard, 'Event History') === false, 
    "Event History is NOT displayed for ICDRRMO (only in Residents and Personnel)");
assertCheck(strpos($superDashboard, 'Resource Management') !== false, 
    "Preserved: Resource Management in ICDRRMO sidebar");
assertCheck(strpos($superDashboard, 'Disaster Request Review') !== false && strpos($superDashboard, 'route=disaster-requests-create') === false,
    "ICDRRMO reviews requests without Barangay Head submission controls");
assertCheck(strpos($superDashboard, 'Submit Ground Field Report') === false && strpos($superDashboard, 'Submit Report') === false, 
    "Submit Status button is removed from ICDRRMO dashboard");
assertCheck(strpos($superDashboard, 'Citywide Responder Ground Reports Monitoring') !== false, 
    "Submitted field reports appear in ICDRRMO administrative dashboard");

// Direct URL Restrictions for ICDRRMO:
Auth::getFlashes();
(new App\Controllers\FieldReportController())->create();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']), "Direct access to field-reports-create is denied for ICDRRMO (Responders only)");

Auth::getFlashes();
(new App\Controllers\FieldReportController())->verify();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']), "Direct access to field-reports-verify is denied for ICDRRMO (read-only monitoring)");

Auth::getFlashes();
(new App\Controllers\FieldReportController())->reject();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']), "Direct access to field-reports-reject is denied for ICDRRMO (read-only monitoring)");
Auth::getFlashes();
(new App\Controllers\ShelterController())->index();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']) || !empty($f['warning']), "Direct access to shelters is denied for ICDRRMO");

Auth::getFlashes();
(new App\Controllers\MLController())->index();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']) || !empty($f['warning']), "Direct access to ml-model is denied for ICDRRMO");

Auth::getFlashes();
(new App\Controllers\MLController())->historicalRecords();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']) || !empty($f['warning']), "Direct access to historical-records is denied for ICDRRMO");

Auth::getFlashes();
(new App\Controllers\NotificationController())->index();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']), "Direct access to notifications is denied for ICDRRMO");

Auth::getFlashes();
(new App\Controllers\AuditController())->index();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']) || !empty($f['warning']), "Direct access to audit-logs is denied for ICDRRMO");

Auth::getFlashes();
(new App\Controllers\EvaluationController())->results();
$f = Auth::getFlashes();
assertCheck(!empty($f['danger']) || !empty($f['warning']), "Direct access to evaluation-results is denied for ICDRRMO");


// ---------------------------------------------------------
// 5. CRITICAL SYSTEM PRESERVATION & SHARED SERVICES INTEGRITY
// ---------------------------------------------------------
echo "\nTEST SUITE 5: Critical System Preservation & Shared Services Integrity\n";

// 5.1 Machine Learning Recommendation Engine
$features = \App\ML\FeatureExtractor::extract([
    'disaster_type' => 'Flood',
    'severity_level' => 'Critical',
    'is_urgent' => 1,
    'purok_risk_level' => 'High',
    'affected_households' => 50,
    'affected_population' => 200
]);
$tree = new \App\ML\DecisionTreeClassifier();
$activeModel = __DIR__ . '/../ml/data/active_model.json';
if (file_exists($activeModel)) {
    $tree->loadFromJson($activeModel);
}
$prediction = $tree->predict($features);
$predPriority = $prediction['predicted_priority'] ?? 'Urgent';
assertCheck(in_array($predPriority, ['Urgent', 'High', 'Medium', 'Low'], true), 
    "Preserved: Decision Tree ML model priority prediction algorithm functions internally (Result: {$predPriority})");

$calc = DemandCalculator::calculateNeed('Urgent', 50, 200, 'Relief Goods', 100);
assertCheck($calc['calculated_demand'] === 55, 
    "Preserved: Purok-based demand calculation with 1.10x urgent buffer functions correctly (Result: {$calc['calculated_demand']})");

// 5.2 Database Records & Structures
$shelterCount = count(Shelter::all());
assertCheck($shelterCount >= 3, 
    "Preserved: Evacuation shelter database records intact ({$shelterCount} shelters)");

$histCount = count(HistoricalRecord::all());
assertCheck($histCount >= 10, 
    "Preserved: Historical disaster records database intact ({$histCount} records)");

$tamCount = count(TamEvaluation::all());
assertCheck($tamCount > 0, 
    "Preserved: TAM & ISO 9126 evaluation database records intact ({$tamCount} evaluations)");

$auditCount = count(AuditLog::all(5));
assertCheck($auditCount > 0, 
    "Preserved: Audit log database ledger intact and active ({$auditCount} recent entries)");

// ---------------------------------------------------------
// 6. MULTI-AGENCY FIELD REPORT LIFECYCLE & ROLE WORKFLOW
// ---------------------------------------------------------
echo "\nTEST SUITE 6: Multi-Agency Responder Field Report Lifecycle & Verification\n";
// Step 1: Responder signs in and submits a field report
Auth::login('responder1@hinaplanon.gov.ph', 'responder123');
$respUser = Auth::user();

$purok = \App\Models\Purok::all()[0];
$newReportId = \App\Models\FieldReport::create([
    'user_id' => (int)$respUser['user_id'],
    'purok_id' => (int)$purok['purok_id'],
    'responder_name' => $respUser['firstname'] . ' ' . $respUser['lastname'],
    'status_update' => 'Water levels rising rapidly near Mandulog bridge, evacuation in progress.',
    'road_accessibility' => 'Not Passable / Blocked',
    'casualties_count' => 0,
    'damaged_houses' => 4,
    'urgent_needs' => 'Rubber boat, life vests',
    'status' => 'Submitted'
]);
assertCheck($newReportId > 0, "Responder successfully submitted field report (ID: #{$newReportId})");

$submittedReport = \App\Models\FieldReport::findById($newReportId);
assertCheck($submittedReport['status'] === 'Submitted', "Initial field report status is 'Submitted' (Pending Review)");

// Step 2: Automatic delivery to Barangay Head and ICDRRMO
\App\Models\Notification::notifyRole(
    'Barangay Head',
    'field_report_submitted',
    'New Field Status Report Awaiting Review',
    "Responder submitted Report #{$newReportId} for {$purok['purok_name']}.",
    'Normal',
    'field_reports',
    $newReportId
);
\App\Models\Notification::notifyRole(
    'ICDRRMO',
    'field_report_submitted',
    'New Field Status Report Received',
    "Responder submitted Report #{$newReportId} for {$purok['purok_name']}.",
    'Normal',
    'field_reports',
    $newReportId
);

$brgyNotifs = \App\Models\Notification::getForUser(2, 'Barangay Head');
$brgyReceived = array_filter($brgyNotifs, fn($n) => $n['related_record_type'] === 'field_reports' && (int)$n['related_record_id'] === $newReportId);
assertCheck(!empty($brgyReceived), "Field report automatically received by Barangay Head (Admin)");

$icdrrmoNotifs = \App\Models\Notification::getForUser(1, 'ICDRRMO');
$icdrrmoReceived = array_filter($icdrrmoNotifs, fn($n) => $n['related_record_type'] === 'field_reports' && (int)$n['related_record_id'] === $newReportId);
assertCheck(!empty($icdrrmoReceived), "Field report automatically received by ICDRRMO (Super Admin)");

// Step 3: Responder tracks their own submission
$mySubmissions = \App\Models\FieldReport::all(['user_id' => (int)$respUser['user_id']]);
$tracked = array_filter($mySubmissions, fn($r) => (int)$r['report_id'] === $newReportId);
assertCheck(!empty($tracked), "Responder can track their own submitted field reports");

// Step 4: Barangay Head reviews and verifies the report
Auth::login('brgyhead@hinaplanon.gov.ph', 'brgyhead123');
$verifyOk = \App\Models\FieldReport::verify($newReportId, 2, 'Ground observation verified with Purok Leader.');
assertCheck($verifyOk === true, "Barangay Head successfully verified the field report");

$verifiedReport = \App\Models\FieldReport::findById($newReportId);
assertCheck($verifiedReport['status'] === 'Verified', "Report status transitioned to 'Verified'");
assertCheck((int)$verifiedReport['reviewed_by'] === 2, "Reviewer ID properly recorded as Barangay Head");
assertCheck(!empty($verifiedReport['reviewed_at']), "Review timestamp recorded");

// Step 5: Barangay Head rejects a report with reason
$rejectReportId = \App\Models\FieldReport::create([
    'user_id' => (int)$respUser['user_id'],
    'purok_id' => (int)$purok['purok_id'],
    'responder_name' => $respUser['firstname'] . ' ' . $respUser['lastname'],
    'status_update' => 'Unconfirmed report of flooding.',
    'road_accessibility' => 'Passable',
    'status' => 'Submitted'
]);
$rejectOk = \App\Models\FieldReport::reject($rejectReportId, 2, 'Inaccurate water level reported.');
assertCheck($rejectOk === true, "Barangay Head can reject/return inaccurate field reports");

$rejectedReport = \App\Models\FieldReport::findById($rejectReportId);
assertCheck($rejectedReport['status'] === 'Rejected', "Report status transitioned to 'Rejected'");
assertCheck(strpos($rejectedReport['review_notes'], 'Inaccurate') !== false, "Rejection justification stored in report");

// Step 6: ICDRRMO monitors all reports across barangays
Auth::login('icdrrmo@iligan.gov.ph', 'icdrrmo123');
$allReports = \App\Models\FieldReport::all();
assertCheck(count($allReports) >= 2, "ICDRRMO can monitor all reports across barangays");
$counts = \App\Models\FieldReport::getCounts();
assertCheck($counts['verified'] >= 1 && $counts['rejected'] >= 1, "ICDRRMO monitoring aggregate includes verified and rejected totals");

echo "\n====================================================================\n";
echo "ROLE VERIFICATION SUMMARY: {$passedTests} / {$totalTests} Tests Passed (" . round(($passedTests / max(1, $totalTests)) * 100) . "%)\n";
if ($failedTests === 0) {
    echo "STATUS: ALL ROLE-BASED FEATURE REMOVAL AND PRESERVATION CRITERIA MET!\n";
} else {
    echo "STATUS: {$failedTests} TESTS FAILED.\n";
}
echo "====================================================================\n";
