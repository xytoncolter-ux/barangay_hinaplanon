<?php
use App\Core\Auth;
use App\Support\ActivityCalendar;

$calendar = ActivityCalendar::build($activities, $_GET);
$ce = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$recorded = static fn($v) => $v === null || trim((string)$v) === '' ? 'Not recorded' : (string)$v;
$canSchedule = Auth::hasRole(['Barangay Head','ICDRRMO']);
$canEvaluate = Auth::hasRole(['Barangay Head','ICDRRMO','Responder']);
$base = ['route'=>'preparedness-calendar','tab'=>'activities','month'=>$calendar['month'],
    'cal_view'=>$calendar['view'],'cal_status'=>$calendar['status'],'cal_search'=>$calendar['search'],
    'cal_scope'=>$calendar['scope'],'cal_date'=>$calendar['focus']->format('Y-m-d')];
$calUrl = static fn($changes=[]) => 'index.php?'.http_build_query(array_replace($base,$changes), '', '&', PHP_QUERY_RFC3986);
$monthNames = [1=>'January','February','March','April','May','June','July','August','September','October','November','December'];
$prev = $calendar['view']==='week' ? $calendar['start']->modify('-7 days') : $calendar['first']->modify('-1 month');
$next = $calendar['view']==='week' ? $calendar['start']->modify('+7 days') : $calendar['first']->modify('+1 month');
$jsonDays=[];
foreach ($calendar['days'] as $date=>$day) $jsonDays[$date]=['label'=>$day['date']->format('l, F j, Y'),'ids'=>array_keys($day['events'])];
// Day details may contain visible events even when the list scope is restricted.
$detailEvents = $calendar['cards'] + $calendar['visible'];
?>
<link rel="stylesheet" href="assets/css/activity-calendar.css?v=20261106-calendar2">
<section class="activity-calendar" aria-labelledby="activity-calendar-heading" data-view="<?= $ce($calendar['view']) ?>" data-scope="<?= $ce($calendar['scope']) ?>" data-can-schedule="<?= $canSchedule?'true':'false' ?>">
    <div class="cal-section-heading">
        <div><h2 id="activity-calendar-heading">Monthly Highlights &amp; Activity Schedule</h2><p>Barangay Hinaplanon · Asia/Manila</p></div>
        <div class="page-actions">
            <?php if ($canSchedule): ?><a class="btn btn-primary" href="index.php?route=preparedness-create"><i class="fa fa-calendar-plus" aria-hidden="true"></i> Schedule New Activity</a><?php endif; ?>
        </div>
    </div>
    <details class="cal-summary"><summary>Schedule summary</summary><div class="grid-cols-4 cal-metrics">
        <div class="stat-card"><div class="stat-label">Recorded activities</div><div class="stat-value"><?= $calendar['stats']['total'] ?></div><div class="stat-sub">With a valid scheduled date</div></div>
        <div class="stat-card"><div class="stat-label">Upcoming activities</div><div class="stat-value"><?= $calendar['stats']['upcoming'] ?></div><div class="stat-sub">Future scheduled starts</div></div>
        <div class="stat-card"><div class="stat-label">Completed activities</div><div class="stat-value"><?= $calendar['stats']['completed'] ?></div><div class="stat-sub">Recorded Completed status only</div></div>
        <div class="stat-card"><div class="stat-label">Recorded turnout</div><div class="stat-value"><?= number_format($calendar['stats']['turnout']) ?></div><div class="stat-sub">Evaluation attendance, not capacity</div></div>
    </div></details>
    <?php if ($calendar['invalid']): ?><div class="alert alert-warning" role="status"><div><?= count($calendar['invalid']) ?> activity record(s) have missing or invalid dates and cannot be placed on a calendar. No date has been invented.<details><summary>Records without a reliable calendar date</summary><ul><?php foreach ($calendar['invalid'] as $id=>$a): ?><li>#<?= (int)$id ?> · <?= $ce($recorded($a['title']??null)) ?> · <?= $ce($recorded($a['status']??null)) ?> · Scheduled date: Not recorded / invalid</li><?php endforeach; ?></ul></details></div></div><?php endif; ?>
    <div class="card cal-shell">
        <div class="card-header cal-toolbar">
            <div class="page-actions cal-period-navigation">
                <a class="btn btn-outline btn-sm" href="<?= $ce($calUrl(['month'=>$calendar['now']->format('Y-m'),'cal_date'=>$calendar['today']])) ?>">Today</a>
                <?php if ((int)$prev->format('Y')>=1): ?><a class="btn btn-outline btn-sm" rel="prev" href="<?= $ce($calUrl(['month'=>$prev->format('Y-m'),'cal_date'=>$prev->format('Y-m-d')])) ?>" aria-label="Previous <?= $calendar['view']==='week'?'week':'month' ?>">‹ Prev</a><?php endif; ?>
                <?php if ((int)$next->format('Y')<=9999): ?><a class="btn btn-outline btn-sm" rel="next" href="<?= $ce($calUrl(['month'=>$next->format('Y-m'),'cal_date'=>$next->format('Y-m-d')])) ?>" aria-label="Next <?= $calendar['view']==='week'?'week':'month' ?>">Next ›</a><?php endif; ?>
                <details class="cal-picker" id="cal-picker" data-year="<?= (int)$calendar['first']->format('Y') ?>" data-month="<?= (int)$calendar['first']->format('n') ?>">
                    <summary id="cal-period-title" aria-label="Choose month and year"><?= $ce($calendar['first']->format('F Y')) ?> <span aria-hidden="true">⌄</span></summary>
                    <div class="cal-picker-panel" aria-label="Month and year picker">
                        <div class="cal-picker-header"><button type="button" class="cal-picker-arrow" data-picker-prev aria-label="Previous year" hidden>‹</button><button type="button" class="cal-picker-year" data-picker-year hidden><?= $calendar['first']->format('Y') ?></button><button type="button" class="cal-picker-arrow" data-picker-next aria-label="Next year" hidden>›</button></div>
                        <div class="cal-picker-grid" data-month-grid aria-label="Months"><?php foreach ($monthNames as $number=>$name): ?><a href="<?= $ce($calUrl(['month'=>sprintf('%04d-%02d',(int)$calendar['first']->format('Y'),$number),'cal_date'=>sprintf('%04d-%02d-01',(int)$calendar['first']->format('Y'),$number)])) ?>" data-picker-month="<?= $number ?>" <?= (int)$calendar['first']->format('n')===$number?'aria-current="date"':'' ?>><?= substr($name,0,3) ?></a><?php endforeach; ?></div>
                        <div class="cal-picker-grid" data-year-grid aria-label="Years" hidden></div>
                        <button class="cal-picker-back" data-picker-back type="button" hidden>Back to months</button>
                        <form class="cal-picker-fallback" method="get"><?php foreach ($base as $key=>$v): if (in_array($key,['month','cal_date'],true)) continue; ?><input type="hidden" name="<?= $ce($key) ?>" value="<?= $ce($v) ?>"><?php endforeach; ?><input type="hidden" name="cal_month" value="<?= (int)$calendar['first']->format('n') ?>"><label>Year<input class="form-control" type="number" name="cal_year" min="1" max="9999" value="<?= (int)$calendar['first']->format('Y') ?>" required></label><button class="btn btn-outline btn-sm">Go</button></form>
                    </div>
                </details>
            </div>
            <nav class="page-actions cal-view-switch" aria-label="Calendar view"><?php foreach (['month'=>'Month View','week'=>'Week View','list'=>'List View'] as $key=>$label): ?><a class="btn btn-sm <?= $calendar['view']===$key?'btn-primary':'btn-outline' ?>" href="<?= $ce($calUrl(['cal_view'=>$key])) ?>" <?= $calendar['view']===$key?'aria-current="true"':'' ?>><?= $label ?></a><?php endforeach; ?></nav>
        </div>
        <div class="card-body">
            <form method="get" id="calendar-filter-form" class="cal-search-form">
                <input type="hidden" name="route" value="preparedness-calendar"><input type="hidden" name="tab" value="activities">
                <input type="hidden" name="month" value="<?= $ce($calendar['month']) ?>"><input type="hidden" name="cal_date" value="<?= $ce($calendar['focus']->format('Y-m-d')) ?>">
                <input type="hidden" name="cal_view" value="<?= $ce($calendar['view']) ?>">
                <label for="cal-search" class="cal-search-label"><span class="sr-only">Search activities</span><input class="form-control" type="search" id="cal-search" name="cal_search" maxlength="160" value="<?= $ce($calendar['search']) ?>" placeholder="Search activities, dates or puroks…" aria-describedby="cal-search-help"></label>
                <label for="cal-status" class="cal-filter-label"><span class="sr-only">Activity filter</span><select class="form-control" id="cal-status" name="cal_status" data-auto-submit><?php foreach (['all'=>'All Activities','upcoming'=>'Upcoming','ongoing'=>'Ongoing','completed'=>'Completed','cancelled'=>'Cancelled','historical'=>'Historical Activities','postponed'=>'Postponed','urgent'=>'Urgent attention'] as $key=>$label): ?><option value="<?= $key ?>" <?= $calendar['status']===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                <label class="cal-all-dates"><input type="checkbox" id="cal-scope" name="cal_scope" value="all" <?= $calendar['scope']==='all'?'checked':'' ?> data-auto-submit> All dates</label>
                <button class="btn btn-outline btn-sm" type="submit" aria-label="Search activities">Search</button>
                <?php if ($calendar['search']!=='' || $calendar['status']!=='all' || $calendar['scope']!=='month'): ?><a class="cal-clear" href="<?= $ce($calUrl(['cal_status'=>'all','cal_search'=>'','cal_scope'=>'month'])) ?>">Clear filters</a><?php endif; ?>
            </form>
            <p id="cal-search-help" class="sr-only">All dates searches the full archive. The grid always follows the displayed period. Historical dates do not prove completion.</p>
            <?php if ($calendar['view']==='week'): ?><p class="field-hint"><?= $ce($calendar['start']->format('M j').' – '.$calendar['end']->format('M j, Y')) ?></p><?php endif; ?>
            <details class="cal-status-guide"><summary>Status guide</summary><div class="cal-legend" aria-label="Activity status legend"><span class="cal-status cal-upcoming">Upcoming</span><span class="cal-status cal-completed">Completed</span><span class="cal-status cal-ongoing">Ongoing</span><span class="cal-status cal-cancelled">Cancelled</span><span class="cal-status cal-postponed">Postponed</span><span class="cal-status cal-urgent">Urgent attention</span><span class="cal-today-legend">Today (teal outline)</span></div></details>
            <?php if ($calendar['view']!=='list'): ?>
            <p class="sr-only">Select an activity for details, or +N more to view all activities on that date.</p>
            <div class="calendar-scroll" tabindex="0" role="region" aria-label="<?= $calendar['view']==='week'?'Weekly':'Monthly' ?> activity calendar, Sunday through Saturday">
                <div class="calendar-grid cal-weekdays"><?php foreach (['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $day): ?><div><abbr title="<?= $day ?>"><?= substr($day,0,3) ?></abbr></div><?php endforeach; ?></div>
                <div class="calendar-grid cal-days">
                    <?php for ($blank=0;$blank<(int)$calendar['start']->format('w');$blank++): ?><div class="cal-blank" aria-hidden="true"></div><?php endfor; ?>
                    <?php foreach ($calendar['days'] as $date=>$day): ?>
                    <article class="cal-day-cell <?= $date===$calendar['today']?'is-today':'' ?> <?= $day['events']?'has-events':'' ?>" id="cal-day-<?= $ce($date) ?>" data-date="<?= $ce($date) ?>">
                        <a class="cal-date-button" href="#cal-day-list-<?= $ce($date) ?>" data-calendar-day="<?= $ce($date) ?>" aria-label="<?= $ce($day['date']->format('l, F j, Y')) ?> — <?= count($day['events']) ?> activities" <?= $date===$calendar['today']?'aria-current="date"':'' ?>><span><?= $day['date']->format('j') ?></span><?php if ($date===$calendar['today']): ?><span class="cal-today-label">Today</span><?php endif; ?><?php if ($day['events']): ?><span class="cal-day-count"><?= count($day['events']) ?> <span class="sr-only">activities</span></span><?php endif; ?></a>
                        <div class="cal-day-events" id="cal-day-list-<?= $ce($date) ?>"><?php $eventIndex=0; foreach ($day['events'] as $e): ?><a class="cal-event cal-<?= $ce($e['status_key']) ?>" href="#activity-card-<?= $e['id'] ?>" data-calendar-event="<?= $e['id'] ?>" title="<?= $ce($recorded($e['record']['title']??null).' · '.$e['status_label']) ?>" aria-label="<?= $ce($recorded($e['record']['title']??null).' · '.$e['status_label']) ?>" <?= $eventIndex++>=2?'hidden':'' ?>><span class="cal-event-title"><?= $ce($recorded($e['record']['title']??null)) ?></span><span class="cal-event-status"><?= $ce($e['status_label']) ?></span></a><?php endforeach; ?></div>
                        <a class="cal-more" href="#eventCardsGrid" data-calendar-day="<?= $ce($date) ?>" data-more-for="<?= $ce($date) ?>" <?= count($day['events'])<=2?'hidden':'' ?>>+<?= max(0,count($day['events'])-2) ?> more</a>
                    </article>
                    <?php endforeach; ?>
                    <?php $trailing=(7-(((int)$calendar['start']->format('w')+count($calendar['days']))%7))%7; for($blank=0;$blank<$trailing;$blank++): ?><div class="cal-blank" aria-hidden="true"></div><?php endfor; ?>
                </div>
            </div>
            <?php if (!$calendar['visible']): ?><p class="cal-empty" role="status">No matching activities are recorded for this displayed period. Try another month or choose All dates.</p><?php endif; ?>
            <?php endif; ?>
            <p class="field-hint" id="cal-results" role="status"><?= count($calendar['cards']) ?> matching activities in <?= $calendar['scope']==='all'?'all recorded dates':'the displayed period' ?>. Select an individual activity for details.</p>
        </div>
    </div>
    <details class="cal-records" open><summary><?= $calendar['view']==='list'?'Chronological activity list':'Activities & details' ?> <span><?= count($calendar['cards']) ?></span></summary>
    <div class="<?= $calendar['view']==='list'?'cal-list-view':'grid-cols-2' ?>" id="eventCardsGrid">
    <?php foreach ($detailEvents as $event): $act=$event['record']; ?>
        <article class="card event-activity-card" id="activity-card-<?= $event['id'] ?>" <?= !isset($calendar['cards'][$event['id']])?'hidden':'' ?>>
            <div class="cal-detail-content">
                <?php require __DIR__.'/calendar_activity.php'; ?>
            </div>
            <div class="card-footer page-actions"><a class="btn btn-outline btn-sm" href="#activity-card-<?= $event['id'] ?>" data-calendar-event="<?= $event['id'] ?>">View activity details</a></div>
        </article>
    <?php endforeach; ?>
    </div>
    <?php if (!$calendar['cards']): ?><div class="cal-empty">No activities match the selected search, filter and date range.</div><?php endif; ?>
    </details>
</section>
<div id="dateDetailsModal" class="cal-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="calModalDateTitle">
    <div class="cal-modal-dialog">
        <div class="cal-modal-header"><h3 id="calModalDateTitle">Activity details</h3><button class="cal-modal-close" type="button" data-calendar-close aria-label="Close details dialog">×</button></div>
        <div class="cal-modal-body" id="calModalBody"></div>
        <div class="cal-modal-footer"><span>Asia/Manila · Recorded schedules only</span><div id="calModalFooterAction"></div><button class="btn btn-outline" type="button" data-calendar-close>Close Window</button></div>
    </div>
</div>
<script type="application/json" id="calendar-day-data"><?= json_encode($jsonDays,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="assets/js/activity-calendar.js?v=20261106-calendar2" defer></script>
