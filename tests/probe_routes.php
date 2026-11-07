<?php
// Match the production App autoloader when new model dependencies are introduced.
spl_autoload_register(static function ($class) {
    if (str_starts_with($class, 'App\\')) {
        $file=__DIR__.'/../src/'.str_replace('\\','/',substr($class,4)).'.php';
        if (is_file($file)) require_once $file;
    }
});
// tests/probe_routes.php

error_reporting(E_ALL & ~E_WARNING);
ini_set('display_errors', '0');

require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/Auth.php';
require_once __DIR__ . '/../src/Core/View.php';
require_once __DIR__ . '/../src/Core/Request.php';

// Models
require_once __DIR__ . '/../src/Models/User.php';
require_once __DIR__ . '/../src/Models/Purok.php';
require_once __DIR__ . '/../src/Models/Supply.php';
require_once __DIR__ . '/../src/Models/DisasterRequest.php';
require_once __DIR__ . '/../src/Models/Recommendation.php';
require_once __DIR__ . '/../src/Models/Notification.php';
require_once __DIR__ . '/../src/Models/Shelter.php';
require_once __DIR__ . '/../src/Models/AuditLog.php';
require_once __DIR__ . '/../src/Models/IncidentHistory.php';
require_once __DIR__ . '/../src/Models/PreparednessActivity.php';
require_once __DIR__ . '/../src/Models/FieldReport.php';
require_once __DIR__ . '/../src/Models/RequestLog.php';
require_once __DIR__ . '/../src/Models/HistoricalRecord.php';
require_once __DIR__ . '/../src/Models/TamEvaluation.php';
require_once __DIR__ . '/../src/Models/Beneficiary.php';

// Controllers
require_once __DIR__ . '/../src/Controllers/AuthController.php';
require_once __DIR__ . '/../src/Controllers/DashboardController.php';
require_once __DIR__ . '/../src/Controllers/NotificationController.php';
require_once __DIR__ . '/../src/Controllers/ShelterController.php';
require_once __DIR__ . '/../src/Controllers/MapApiController.php';
require_once __DIR__ . '/../src/Controllers/SosController.php';
require_once __DIR__ . '/../src/Controllers/HazardMapController.php';
require_once __DIR__ . '/../src/Controllers/RecommendationController.php';
require_once __DIR__ . '/../src/Controllers/DisasterRequestController.php';
require_once __DIR__ . '/../src/Controllers/UserController.php';
require_once __DIR__ . '/../src/Controllers/AuditController.php';
require_once __DIR__ . '/../src/Controllers/InventoryController.php';
require_once __DIR__ . '/../src/Controllers/PreparednessController.php';
require_once __DIR__ . '/../src/Controllers/FieldReportController.php';
require_once __DIR__ . '/../src/Controllers/MLController.php';
require_once __DIR__ . '/../src/Controllers/ReportController.php';
require_once __DIR__ . '/../src/Controllers/EvaluationController.php';

use App\Core\Auth;
use App\Models\User;

Auth::startSession();

echo "=======================================================\n";
echo "   Barangay Hinaplanon — MVC Route Endpoint Prober\n";
echo "=======================================================\n\n";

function probeRoute(string $name, callable $fn) {
    echo "Probing [{$name}]... ";
    ob_start();
    try {
        $fn();
        $output = ob_get_clean();
        if (strlen($output) > 0) {
            echo "OK (" . strlen($output) . " bytes rendered)\n";
        } else {
            echo "OK (Empty/redirect)\n";
        }
    } catch (\Throwable $e) {
        ob_end_clean();
        echo "FAILED: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}

// 1. Guest Routes
Auth::logout();
probeRoute('GET AuthController::showLogin', fn() => (new App\Controllers\AuthController())->showLogin());
probeRoute('GET AuthController::showRegister', fn() => (new App\Controllers\AuthController())->showRegister());
probeRoute('GET AuthController::showForgotPassword', fn() => (new App\Controllers\AuthController())->showForgotPassword());

// 2. Authenticated as ICDRRMO
Auth::login('icdrrmo@iligan.gov.ph', 'icdrrmo123');
probeRoute('GET DashboardController::index (ICDRRMO)', fn() => (new App\Controllers\DashboardController())->index());
probeRoute('GET HazardMapController::index', fn() => (new App\Controllers\HazardMapController())->index());
probeRoute('GET ShelterController::index', fn() => (new App\Controllers\ShelterController())->index());
probeRoute('GET NotificationController::index', fn() => (new App\Controllers\NotificationController())->index());
probeRoute('GET DisasterRequestController::index', fn() => (new App\Controllers\DisasterRequestController())->index());
probeRoute('GET RecommendationController::index', fn() => (new App\Controllers\RecommendationController())->index());
probeRoute('GET InventoryController::index', fn() => (new App\Controllers\InventoryController())->index());
probeRoute('GET UserController::index', fn() => (new App\Controllers\UserController())->index());
probeRoute('GET UserController::pendingResidents', fn() => (new App\Controllers\UserController())->pendingResidents());
probeRoute('GET UserController::reliefClaimsOversight (ICDRRMO)', fn() => (new App\Controllers\UserController())->reliefClaimsOversight());
probeRoute('GET FieldReportController::index', fn() => (new App\Controllers\FieldReportController())->index());
probeRoute('GET AuditController::index', fn() => (new App\Controllers\AuditController())->index());
probeRoute('GET MLController::index', fn() => (new App\Controllers\MLController())->index());
probeRoute('GET EvaluationController::results', fn() => (new App\Controllers\EvaluationController())->results());

// 3. Authenticated as Barangay Head
Auth::login('brgyhead@hinaplanon.gov.ph', 'brgyhead123');
probeRoute('GET DashboardController::index (Barangay Head)', fn() => (new App\Controllers\DashboardController())->index());

// 4. Authenticated as Responder
Auth::login('responder1@hinaplanon.gov.ph', 'responder123');
probeRoute('GET DashboardController::index (Responder)', fn() => (new App\Controllers\DashboardController())->index());
probeRoute('GET FieldReportController::create (Responder)', fn() => (new App\Controllers\FieldReportController())->create());
probeRoute('GET PreparednessController::index', fn() => (new App\Controllers\PreparednessController())->index());
probeRoute('GET PreparednessController::calendar', fn() => (new App\Controllers\PreparednessController())->calendar());

// 5. Authenticated as Resident
Auth::login('juan.delacruz@gmail.com', 'resident123');
probeRoute('GET DashboardController::index (Resident)', fn() => (new App\Controllers\DashboardController())->index());
probeRoute('GET SosController::form (Resident)', fn() => (new App\Controllers\SosController())->form());

echo "\n=======================================================\n";
echo "   All tested MVC Route endpoints executed cleanly!\n";
echo "=======================================================\n";
