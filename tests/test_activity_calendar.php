<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Support/ActivityCalendar.php';
use App\Support\ActivityCalendar as Calendar;
$failed=0; $passed=0;
function checkCalendar(bool $condition,string $label): void {
    global $failed,$passed;
    echo ($condition?'[PASS] ':'[FAIL] ').$label.PHP_EOL;
    $condition?$passed++:$failed++;
}
$now=new DateTimeImmutable('2026-11-06 12:00:00',Calendar::timezone());
function fixture(int $id,string $date,string $status='Scheduled',array $extra=[]): array {
    return array_replace(['preparedness_activities_id'=>$id,'schedule_date'=>$date,'status'=>$status,
        'title'=>'QA Activity '.$id,'activity_type'=>'First-aid training','purok_name'=>'Purok 2'],$extra);
}
$rows=[fixture(1,'2011-12-17 08:00:00','Completed'),fixture(2,'2024-02-29 09:00:00'),
    fixture(3,'2026-11-06 08:00:00','Ongoing'),fixture(4,'2026-11-06 09:00:00','Completed'),
    fixture(5,'2027-01-02 08:00:00'),fixture(6,'2026-11-12','Cancelled'),
    fixture(7,'2026-11-13','Postponed'),fixture(8,'2026-02-30'),
    fixture(9,'2024-02-28 10:00:00','Completed',['end_date'=>'2024-03-02 16:00:00']),
    fixture(10,'2026-05-05'),fixture(11,'2024-02-29T20:00:00Z')];
$feb=Calendar::build($rows,['month'=>'2024-02'],$now);
checkCalendar(count($feb['days'])===29,'Leap-year February has all 29 days');
checkCalendar((int)$feb['first']->format('w')===4,'February 2024 begins on Thursday (Sun=0)');
checkCalendar(isset($feb['days']['2024-02-29']['events'][2]),'Leap-day activity appears on its actual day');
checkCalendar(isset($feb['days']['2024-02-28']['events'][9],$feb['days']['2024-02-29']['events'][9]),'Explicit multi-day range spans both February dates');
$march=Calendar::build($rows,['month'=>'2024-03'],$now);
checkCalendar(isset($march['days']['2024-03-01']['events'][9],$march['days']['2024-03-02']['events'][9]),'Multi-day range continues across month boundary');
checkCalendar(!isset($march['days']['2024-03-03']['events'][9]),'Multi-day range ends inclusively without extra day');
checkCalendar(isset($march['days']['2024-03-01']['events'][11]),'UTC timestamp converted to correct Manila date');
checkCalendar(count(Calendar::build([],['month'=>'2100-02'],$now)['days'])===28,'Century 2100 is not a leap year');
checkCalendar(count(Calendar::build([],['month'=>'2000-02'],$now)['days'])===29,'Century 2000 is a leap year');
foreach ([1=>31,4=>30,6=>30,11=>30,12=>31] as $m=>$days) {
    checkCalendar(count(Calendar::build([],['month'=>sprintf('2026-%02d',$m)],$now)['days'])===$days,'Correct month length: '.$m);
}
$nov=Calendar::build($rows,['month'=>'2026-11'],$now);
checkCalendar(count($nov['days']['2026-11-06']['events'])===2,'Multiple same-day activities remain individual');
checkCalendar($nov['events'][2]['status_key']==='unconfirmed','Past scheduled date is not relabeled Completed');
checkCalendar($nov['events'][6]['status_key']==='cancelled' && $nov['events'][7]['status_key']==='postponed','Cancelled and postponed keep distinct status labels');
checkCalendar(isset($nov['invalid'][8]) && !isset($nov['events'][8]),'Invalid dates excluded without inventing historical schedules');
checkCalendar(!$nov['events'][10]['has_time'] && !$nov['events'][10]['has_end'],'Date-only records do not acquire fabricated start or end times');
$historic=Calendar::build($rows,['cal_scope'=>'all','cal_status'=>'historical','cal_search'=>'December'],$now);
checkCalendar(array_keys($historic['cards'])===[1],'Historical search reaches past years by month name');
$future=Calendar::build($rows,['cal_scope'=>'all','cal_status'=>'upcoming'],$now);
checkCalendar(array_keys($future['cards'])===[5],'Upcoming filter excludes past, completed and cancelled records');
foreach (['2027','2027-01-02','Purok 2','First-aid','Completed'] as $term) {
    checkCalendar(count(Calendar::build($rows,['cal_scope'=>'all','cal_search'=>$term],$now)['cards'])>0,'Search supports '.$term);
}
$week=Calendar::build($rows,['cal_view'=>'week','cal_date'=>'2027-01-02','month'=>'2026-11'],$now);
checkCalendar($week['start']->format('Y-m-d')==='2026-12-27' && $week['end']->format('Y-m-d')==='2027-01-02','Week navigation crosses years Sunday through Saturday');
checkCalendar(count($week['days'])===7 && isset($week['days']['2027-01-02']['events'][5]),'Week view uses same activity IDs and includes Saturday');
$jump=Calendar::build($rows,['cal_year'=>'2011','cal_month'=>'12'],$now);
checkCalendar($jump['month']==='2011-12' && isset($jump['visible'][1]),'Direct year/month navigation retrieves historical events');
foreach (['0001-01','9999-12'] as $month) checkCalendar(Calendar::build([],['month'=>$month],$now)['month']===$month,'Valid boundary month '.$month);
$edgeWeek=Calendar::build([],['month'=>'0001-01','cal_view'=>'week','cal_date'=>'0001-01-01'],$now);
checkCalendar($edgeWeek['start']->format('Y-m-d')==='0001-01-01','Boundary week uses blanks rather than exposing year zero');
foreach (['0000-01','2026-13','bad'] as $month) checkCalendar(Calendar::build([],['month'=>$month],$now)['month']==='2026-11','Invalid month safely falls back: '.$month);
$duplicate=$rows; $duplicate[]=fixture(3,'2026-11-06 08:00:00','Ongoing',['evaluation_id'=>20]);
$dedup=Calendar::build($duplicate,['month'=>'2026-11'],$now);
checkCalendar(count($dedup['days']['2026-11-06']['events'])===2 && $dedup['events'][3]['record']['evaluation_id']===20,'Evaluation joins cannot duplicate activities; latest evaluation retained');
$manila=Calendar::build([],[],new DateTimeImmutable('2026-11-05T18:00:00Z'));
checkCalendar($manila['today']==='2026-11-06','Today is computed in Manila even across UTC midnight');
checkCalendar(Calendar::parseDate('2026-11-06 25:00:00')===null,'Invalid clock time rejected');
checkCalendar(count(Calendar::build($rows,['cal_scope'=>'all','cal_search'=>'February 2024'],$now)['cards'])>0,'Month and year search terms can be combined');
checkCalendar(!Calendar::visibleToRole(fixture(31,'2026-11-06','Draft'),'Resident'),'Unpublished draft statuses withheld from resident backend projection');
checkCalendar(Calendar::visibleToRole(fixture(31,'2026-11-06','Scheduled'),'Resident'),'Existing public scheduled lifecycle remains visible to residents');
$dateOnlyToday=Calendar::build([fixture(32,'2026-11-06')],[],$now);
checkCalendar($dateOnlyToday['events'][32]['upcoming'] && !$dateOnlyToday['events'][32]['has_time'],'Date-only activity today is not falsely expired at an invented midnight start');
$range=Calendar::build([fixture(30,'2026-11-06 10:00:00','Scheduled',['end_date'=>'2026-11-05'])],[],$now);
checkCalendar($range['events'][30]['invalid_end'] && $range['events'][30]['end_date']==='2026-11-06','Invalid end date does not invent a duration');
echo "Calendar checks: {$passed} passed, {$failed} failed".PHP_EOL;
exit($failed?1:0);
