<?php
declare(strict_types=1);
spl_autoload_register(static function($class) { if (str_starts_with($class,'App\\')) { $p=__DIR__.'/../src/'.str_replace('\\','/',substr($class,4)).'.php'; if(is_file($p)) require_once $p; } });
use App\Core\Database;
use App\Models\DisasterResourceIntake as Intake;
use App\Support\ResourceRequestInput as Input;
use App\Support\ResourceRequestSchema;
$db=Database::getInstance(); $failed=0; $passed=0;
function checkResource(bool $ok,string $message): void { global $failed,$passed; echo ($ok?'[PASS] ':'[FAIL] ').$message.PHP_EOL; $ok?$passed++:$failed++; }
function refused(callable $work,string $label): void { try {$work();checkResource(false,$label);} catch(DomainException $e) {checkResource(true,$label);} }
function keyResource(): string {return bin2hex(random_bytes(16));}
$before=$db->query('SELECT COUNT(*) FROM disaster_request_form')->fetchColumn();
ResourceRequestSchema::migrate(); ResourceRequestSchema::migrate();
checkResource($before===$db->query('SELECT COUNT(*) FROM disaster_request_form')->fetchColumn(),'Migration is idempotent and does not rewrite incident records');
$head=(int)$db->query("SELECT user_id FROM users WHERE user_type='Barangay Head' AND deleted_at IS NULL LIMIT 1")->fetchColumn();
$icd=(int)$db->query("SELECT user_id FROM users WHERE user_type='ICDRRMO' AND deleted_at IS NULL LIMIT 1")->fetchColumn();
$resident=(int)$db->query("SELECT user_id FROM users WHERE user_type='Resident' AND deleted_at IS NULL LIMIT 1")->fetchColumn();
$responder=(int)$db->query("SELECT user_id FROM users WHERE user_type='Responder' AND deleted_at IS NULL LIMIT 1")->fetchColumn();
$purok=Intake::puroks()[0];
$input=['purok_id'=>(string)$purok['purok_id'],'disaster_date'=>(new DateTimeImmutable('today',new DateTimeZone('Asia/Manila')))->format('Y-m-d'),
    'disaster_type'=>'Flood','weather_name'=>'Recorded test-only weather','severity_level'=>'High','is_urgent'=>'1','description'=>'QA request only; no official barangay incident is implied.',
    'affected_households'=>'10','affected_population'=>'40','children'=>'12','seniors'=>'5','pregnant_women'=>'0','food_packs'=>'10','food_packs_unit'=>'packs'];
foreach (['affected_households'=>'-1','affected_population'=>'1.5','children'=>'41','severity_level'=>'Injected','food_packs_unit'=>'sacks','disaster_date'=>'2026-02-30'] as $field=>$value) {
    checkResource(isset(Input::validate(array_replace($input,[$field=>$value]),true)['errors'][$field]),'Backend rejects invalid '.$field);
}
checkResource(!Input::validate([],false)['errors'],'An incomplete blank draft is permitted without fabricated required values');
$d=Intake::save([],0,0,keyResource(),false,$head); $draft=Intake::getFor($d,$head);
checkResource($draft['state']==='Draft' && $draft['disaster_request_id']===null,'Draft does not create a fake incident');
checkResource($draft['payload']['affected_population']===null && $draft['payload']['is_urgent']===null,'Unknown draft values remain unknown, not default estimates');
checkResource($before===$db->query('SELECT COUNT(*) FROM disaster_request_form')->fetchColumn(),'Draft does not change operational incident totals');
refused(fn()=>Intake::getFor($d,$icd),'ICDRRMO cannot read a private draft');
foreach ([$icd,$resident,$responder] as $actor) refused(fn()=>Intake::save($input,0,0,keyResource(),true,$actor),'Only Barangay Head can create or submit requests (actor '.$actor.')');
$other=(int)$resident;
$db->prepare("UPDATE users SET user_type='Barangay Head' WHERE user_id=?")->execute([$other]);
refused(fn()=>Intake::getFor($d,$other),'Another Barangay Head cannot read someone else’s draft');
$db->prepare("UPDATE users SET user_type='Resident' WHERE user_id=?")->execute([$other]);
$rev=(int)$draft['revision']; Intake::save(['description'=>'Edited draft only'],$d,$rev,keyResource(),false,$head);
refused(fn()=>Intake::save($input,$d,$rev,keyResource(),false,$head),'Optimistic revision prevents stale draft overwrites');
$draft=Intake::getFor($d,$head); $submitKey=keyResource();
$saved=Intake::save($input,$d,(int)$draft['revision'],$submitKey,true,$head);
$r=Intake::getFor($saved,$head);
checkResource($saved===$d && $r['state']==='Submitted' && (int)$r['disaster_request_id']>0,'Draft submission creates one linked existing incident');
$again=Intake::save($input,$d,(int)$draft['revision'],$submitKey,true,$head);
checkResource($again===$d && (int)$db->query('SELECT COUNT(*) FROM disaster_request_form')->fetchColumn()===(int)$before+1,'Duplicate submission does not create a second incident');
checkResource(count($r['line_ids'])===1 && isset($r['line_ids']['food_packs']),'Requested quantities reuse existing resource_request line IDs');
checkResource($r['payload']['pwds']===null && $r['payload']['pregnant_women']===0,'Missing demographic counts and recorded zero stay distinct');
checkResource(in_array($d,array_map('intval',array_column(Intake::listing($icd),'intake_id')),true),'Submitted request arrives in ICDRRMO queue');
refused(fn()=>Intake::review($d,(int)$r['revision'],keyResource(),'approve','Attempted self approval','High',$head),'Submitter role cannot approve');
$stock=$db->query("SELECT * FROM supply WHERE supply_type='Relief Goods' AND unit_of_measure IN ('food packs','packs') AND deleted_at IS NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$supply=(int)$stock['supply_id'];
$db->prepare('UPDATE supply SET supply_remaining=100,supply_quantity=200,total_release=0 WHERE supply_id=?')->execute([$supply]);
refused(fn()=>Intake::allocate($d,(int)$r['revision'],keyResource(),'food_packs',$supply,5,'Before independent approval',$head),'No stock allocation before independent approval');
putenv('HINAPLANON_ML_API_BASE_URL=http://127.0.0.1:9'); putenv('HINAPLANON_DECISION_TREE_API_ENABLED=1');
Intake::classify($d,$head); $r=Intake::getFor($d,$head);
checkResource($r['ml_status']==='Unavailable' && $r['ml_result']===null && $r['state']==='Submitted','API outage preserves submitted request without fabricated prediction');
$reviewKey=keyResource(); Intake::review($d,(int)$r['revision'],$reviewKey,'approve','Independent ICDRRMO review complete.','High',$icd);
Intake::review($d,(int)$r['revision'],$reviewKey,'approve','Independent ICDRRMO review complete.','High',$icd);
$r=Intake::getFor($d,$head);
checkResource($r['state']==='Approved' && (int)$r['reviewed_by']===$icd && $r['approved_priority']==='High','ICDRRMO approval is distinct from ML output and idempotent');
checkResource((int)$db->query("SELECT supply_remaining FROM supply WHERE supply_id=$supply")->fetchColumn()===100,'Approval alone does not deduct inventory');
$revision=(int)$r['revision'];
refused(fn()=>Intake::allocate($d,$revision,keyResource(),'food_packs',$supply,11,'Too much stock requested',$head),'Cannot allocate beyond requested amount');
$db->prepare('UPDATE supply SET supply_remaining=0 WHERE supply_id=?')->execute([$supply]);
try {Intake::allocate($d,$revision,keyResource(),'food_packs',$supply,5,'Out of stock scenario',$head);checkResource(false,'Shortage rolls back atomically');}
catch (Throwable $e) {checkResource(Intake::getFor($d,$head)['revision']===$r['revision'],'Shortage rolls back atomically');}
$db->prepare('UPDATE supply SET supply_remaining=100 WHERE supply_id=?')->execute([$supply]);
$wrong=$db->query("SELECT supply_id FROM supply WHERE supply_type='Clean Water' LIMIT 1")->fetchColumn();
refused(fn()=>Intake::allocate($d,$revision,keyResource(),'food_packs',(int)$wrong,5,'Wrong inventory category',$head),'Cannot substitute an incompatible stock category/unit');
$allocationKey=keyResource();
Intake::allocate($d,$revision,$allocationKey,'food_packs',$supply,5,'First approved partial release',$head);
Intake::allocate($d,$revision,$allocationKey,'food_packs',$supply,5,'First approved partial release',$head);
checkResource((int)$db->query("SELECT supply_remaining FROM supply WHERE supply_id=$supply")->fetchColumn()===95 && count(Intake::allocations($d,$head))===1,'Duplicate allocation POST deducts stock only once');
refused(fn()=>Intake::allocate($d,$revision,keyResource(),'food_packs',$supply,1,'Stale allocation form submission',$head),'Stale allocation cannot deduct stock');
$r=Intake::getFor($d,$head); checkResource($r['state']==='Partially Allocated','Partial allocations preserve outstanding demand');
Intake::allocate($d,(int)$r['revision'],keyResource(),'food_packs',$supply,5,'Remaining independently authorized release',$icd);
$r=Intake::getFor($d,$head);
checkResource($r['state']==='Allocated' && (int)$db->query("SELECT supply_remaining FROM supply WHERE supply_id=$supply")->fetchColumn()===90,'Complete stock allocation updates workflow and inventory');
Intake::complete($d,(int)$r['revision'],keyResource(),'Coordination and allocation recorded; claims remain separate.',$head);
checkResource(Intake::getFor($d,$head)['state']==='Completed','Authorized completion updates request history');
$rejected=Intake::save($input,0,0,keyResource(),true,$head); $r=Intake::getFor($rejected,$icd);
Intake::review($rejected,(int)$r['revision'],keyResource(),'reject','Recorded request rejected after review.',null,$icd);
$r=Intake::getFor($rejected,$head);
refused(fn()=>Intake::allocate($rejected,(int)$r['revision'],keyResource(),'food_packs',$supply,1,'Cannot allocate rejected demand',$head),'Rejected requests cannot allocate');
$notification=$db->prepare("SELECT COUNT(*) FROM notifications WHERE target_role='ICDRRMO' AND notification_type='resource_request_review' AND related_record_id=?");
$notification->execute([$r['disaster_request_id']]);checkResource((int)$notification->fetchColumn()===1,'Reviewer receives one submission notification');
echo "Resource request checks: $passed passed, $failed failed\n";
exit($failed?1:0);
