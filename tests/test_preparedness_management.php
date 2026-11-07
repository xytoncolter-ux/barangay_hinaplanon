<?php
declare(strict_types=1);
spl_autoload_register(static function ($class) {
    if (str_starts_with($class, 'App\\')) {
        $file = __DIR__.'/../src/'.str_replace('\\','/',substr($class,4)).'.php';
        if (is_file($file)) require_once $file;
    }
});
use App\Core\Database;
use App\Models\PreparednessOverview;
use App\ML\PreparednessAnalysis;
use App\ML\PreparednessQuantityPlan;

$failures=0;
function verify($condition, $label): void {
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ').$label.PHP_EOL;
    if (!$condition) $failures++;
}
$db=Database::getInstance();
$before=$db->query('SELECT * FROM purok_assessments ORDER BY assessment_id')->fetchAll();
$user=(int)$db->query("SELECT user_id FROM users WHERE user_type='Resident' AND deleted_at IS NULL LIMIT 1")->fetchColumn();
$resident=PreparednessOverview::load('Resident',$user);
verify(!$resident['inventory'] && !$resident['allocations'] && !$resident['events'] && !$resident['puroks'], 'Resident projection excludes confidential records');
$ids=$db->prepare('SELECT beneficiary_id FROM relief_beneficiaries WHERE user_id=?'); $ids->execute([$user]);
verify(array_diff(array_column($resident['claims'],'beneficiary_id'),$ids->fetchAll(PDO::FETCH_COLUMN))===[], 'Resident sees only own existing claims');
foreach ($resident['claims'] as $claim) verify(!isset($claim['firstname']) && !isset($claim['remarks']), 'Claim projection excludes personal fields and remarks');
$responder=(int)$db->query("SELECT user_id FROM users WHERE user_type='Responder' AND deleted_at IS NULL LIMIT 1")->fetchColumn();
$staff=PreparednessOverview::load('Responder',$responder);
verify(!$staff['inventory'] && !$staff['claims'], 'Responder has no confidential stock or unassigned claims');
foreach ($staff['allocations'] as $a) verify(in_array($a['recommendation_status'],['Approved','Modified']), 'Responder sees approved allocations only');
$admin=PreparednessOverview::load('Barangay Head',1);
verify($admin['admin'] && count($admin['inventory'])>0, 'Authorized official receives inventory projection');
$analysis=PreparednessAnalysis::analyze($admin['puroks'],$admin['trustedHistory'],$admin['events'],[]);
verify(count($analysis)===count($admin['puroks']), 'Each scoped purok analyzed exactly once');
foreach ($admin['trustedHistory'] as $h) verify(PreparednessOverview::trustedHistory($h),'Only reconciled non-synthetic history reaches analysis');
$base=['is_synthetic'=>0,'is_verified'=>1,'data_source'=>'Original verified source',
    'disaster_date'=>'2020-01-01','disaster_type'=>'Flood','severity_level'=>'High','purok_risk_level'=>'High',
    'affected_purok'=>'Test-only area','affected_households'=>1,'affected_population'=>4,'is_urgent'=>0];
verify(!PreparednessOverview::trustedHistory($base),'Verification flag alone cannot establish original-source reconciliation');
$allow=[PreparednessOverview::historyFingerprint($base)=>['reviewer'=>'QA reviewer','source_reference'=>'QA original source']];
verify(PreparednessOverview::trustedHistory($base,$allow),'Explicitly reconciled record fingerprint can be analyzed');
$base['is_synthetic']=1; verify(!PreparednessOverview::trustedHistory($base),'Synthetic verified flag cannot become official evidence');
$base['is_synthetic']=0; $base['data_source']='TS Basyang Official BDRRMC Records (ECOWEB / CARD NGO Archive)';
verify(!PreparednessOverview::trustedHistory($base),'Known derived legacy import quarantined despite verified flag');
$plan=PreparednessQuantityPlan::calculate(20,2,15);
verify($plan['demand']===40 && $plan['proposed']===15 && $plan['unmet']===25,'Quantity proposal constrained to stock with explicit unmet demand');
verify(PreparednessQuantityPlan::calculate(0,1,15)['proposed']===0,'Verified zero remains zero, not missing');
try { PreparednessQuantityPlan::calculate(-1,1,15); verify(false,'Reject negative need'); } catch (InvalidArgumentException $e) { verify(true,'Reject negative need'); }
verify($before===$db->query('SELECT * FROM purok_assessments ORDER BY assessment_id')->fetchAll(),'Read-only analysis preserves cached human reviews');
exit($failures?1:0);
