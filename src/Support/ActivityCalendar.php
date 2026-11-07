<?php
namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/** Pure, read-only calendar projection. Civil dates are always interpreted in Manila. */
final class ActivityCalendar {
    public static function timezone(): DateTimeZone { return new DateTimeZone('Asia/Manila'); }

    public static function visibleToRole(array $activity, string $role): bool {
        if (!in_array($role,['Resident','Responder','Barangay Head','ICDRRMO'],true)) return false;
        if ($role!=='Resident') return true;
        // Preserve the existing admin-scheduled public lifecycle; never release draft/unknown states.
        // There is no separate public-approval flag in the current activity schema.
        return in_array(strtolower(trim((string)($activity['status']??''))),
            ['scheduled','upcoming','ongoing','in progress','completed','cancelled','canceled','postponed'],true);
    }

    public static function parseDate($value): ?DateTimeImmutable {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?)?$/D', $value)) return null;
        try {
            $date = new DateTimeImmutable($value, self::timezone());
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors && ($errors['warning_count'] || $errors['error_count'])) return null;
            $date = $date->setTimezone(self::timezone());
            $year = (int)$date->format('Y');
            return $year >= 1 && $year <= 9999 ? $date : null;
        } catch (\Throwable $e) { return null; }
    }

    private static function text(array $params, string $key, string $default = ''): string {
        return isset($params[$key]) && is_string($params[$key]) ? trim($params[$key]) : $default;
    }

    public static function build(array $activities, array $params, ?DateTimeImmutable $now = null): array {
        $now = ($now ?? new DateTimeImmutable('now', self::timezone()))->setTimezone(self::timezone());
        $today = $now->format('Y-m-d');
        $month = self::text($params, 'month', $now->format('Y-m'));
        if (isset($params['cal_year'], $params['cal_month'])) {
            $y = filter_var($params['cal_year'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>9999]]);
            $m = filter_var($params['cal_month'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>12]]);
            if ($y !== false && $m !== false) $month = sprintf('%04d-%02d', $y, $m);
        }
        $first = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month) ? self::parseDate($month.'-01') : null;
        if (!$first) $first = $now->modify('first day of this month')->setTime(0,0);
        $month = $first->format('Y-m');
        $view = self::text($params,'cal_view','month');
        if (!in_array($view,['month','week','list'],true)) $view='month';
        $status = self::text($params,'cal_status','all');
        if (!in_array($status,['all','upcoming','ongoing','completed','cancelled','historical','postponed','urgent'],true)) $status='all';
        $search = substr(self::text($params,'cal_search'),0,160);
        $scope = self::text($params,'cal_scope','month') === 'all' ? 'all' : 'month';
        $focus = self::parseDate(self::text($params,'cal_date'));
        if ($focus && $view === 'week') {
            $first=$focus->modify('first day of this month')->setTime(0,0);
            $month=$first->format('Y-m');
        }
        if (!$focus || $focus->format('Y-m') !== $month) $focus = $month === $now->format('Y-m') ? $now->setTime(0,0) : $first;
        $start = $view === 'week' ? $focus->modify('-'.$focus->format('w').' days')->setTime(0,0) : $first;
        $end = $view === 'week' ? $start->modify('+6 days') : $first->modify('last day of this month');
        if ($start < self::parseDate('0001-01-01')) $start=self::parseDate('0001-01-01');
        if ($end > self::parseDate('9999-12-31')) $end=self::parseDate('9999-12-31');
        $events=[]; $invalid=[]; $years=[];
        foreach ($activities as $a) {
            $id = (int)($a['preparedness_activities_id'] ?? 0);
            if (!$id) continue;
            // Multiple evaluation rows must never duplicate an activity on the calendar.
            if (isset($events[$id]) && (int)($events[$id]['record']['evaluation_id'] ?? 0) >= (int)($a['evaluation_id'] ?? 0)) continue;
            $date = self::parseDate($a['schedule_date'] ?? null);
            if (!$date) { $invalid[$id]=$a; continue; }
            $last = self::parseDate($a['end_date'] ?? null);
            if ($last && strlen((string)$a['end_date'])===10) $last=$last->setTime(23,59,59);
            $invalidEnd = isset($a['end_date']) && trim((string)$a['end_date']) !== '' && (!$last || $last < $date);
            if (!$last || $last < $date) $last=$date;
            $rawStatus = trim((string)($a['status'] ?? ''));
            $hasTime = strlen((string)$a['schedule_date'])>10;
            $key = match (strtolower($rawStatus)) {
                'scheduled','upcoming' => 'upcoming', 'ongoing','in progress' => 'ongoing',
                'completed' => 'completed', 'cancelled','canceled' => 'cancelled',
                'postponed' => 'postponed', 'urgent','requires urgent attention' => 'urgent', default=>'unknown'
            };
            $label = $rawStatus === 'Scheduled' ? 'Upcoming (Scheduled)' : ($rawStatus ?: 'Not recorded');
            if ($key === 'upcoming' && ($hasTime ? $date < $now : $date->format('Y-m-d') < $today)) {
                $label = 'Scheduled — start passed; completion not recorded';
                $key = 'unconfirmed';
            } elseif ($key==='upcoming' && !$hasTime) {
                $label = 'Scheduled — time not recorded';
            }
            $event = ['id'=>$id,'record'=>$a,'start'=>$date,'end'=>$last,
                'start_date'=>$date->format('Y-m-d'),'end_date'=>$last->format('Y-m-d'),
                'status_key'=>$key,'status_label'=>$label,'invalid_end'=>$invalidEnd,
                'historical'=>$last->format('Y-m-d') < $today,
                'has_time'=>$hasTime, 'upcoming'=>$key==='upcoming',
                'has_end'=>!$invalidEnd && isset($a['end_date']) && trim((string)$a['end_date']) !== ''];
            $events[$id]=$event;
            $years[(int)$date->format('Y')]=true;
            $years[(int)$last->format('Y')]=true;
        }
        uasort($events,fn($a,$b)=>$a['start']<=>$b['start'] ?: $a['id']<=>$b['id']);
        $matches=array_filter($events,static function($e) use($status,$search,$now) {
            if ($status === 'historical' && !$e['historical']) return false;
            if ($status === 'upcoming' && !$e['upcoming']) return false;
            if (!in_array($status,['all','historical','upcoming'],true) && $status !== $e['status_key']) return false;
            $a=$e['record'];
            $haystack=implode(' ',[$a['title']??'',$a['activity_type']??'',$a['purok_name']??'',
                $a['status']??'',$e['status_label'],$e['start']->format('Y-m-d F M Y j'),$e['end']->format('Y-m-d F M Y j')]);
            if ($search==='') return true;
            $terms=preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
            if (array_reduce($terms,fn($ok,$term)=>$ok && stripos($haystack,$term)!==false,true)) return true;
            // Exact civil date searches also match an explicitly recorded multi-day range.
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D',$search) && self::parseDate($search)) return $search >= $e['start_date'] && $search <= $e['end_date'];
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$search) && ($s=self::parseDate($search.'-01'))) return $s->format('Y-m-d') <= $e['end_date'] && $s->modify('last day of this month')->format('Y-m-d') >= $e['start_date'];
            if (preg_match('/^\d{4}$/D',$search) && self::parseDate($search.'-01-01')) return $search.'-01-01' <= $e['end_date'] && $search.'-12-31' >= $e['start_date'];
            return false;
        });
        $visible=array_filter($matches,fn($e)=>$e['start_date'] <= $end->format('Y-m-d') && $e['end_date'] >= $start->format('Y-m-d'));
        $cards = $scope === 'all' ? $matches : $visible;
        $days=[];
        for ($day=$start; $day<=$end; $day=$day->modify('+1 day')) {
            $key=$day->format('Y-m-d');
            $days[$key]=['date'=>$day,'events'=>array_filter($visible,fn($e)=>$e['start_date']<=$key && $e['end_date']>=$key)];
        }
        foreach (range(max(1,(int)$first->format('Y')-5),min(9999,(int)$first->format('Y')+5)) as $y) $years[$y]=true;
        ksort($years);
        $stats=['total'=>count($events),'upcoming'=>0,'completed'=>0,'turnout'=>0];
        foreach ($events as $e) {
            if ($e['upcoming']) $stats['upcoming']++;
            if ($e['status_key']==='completed') $stats['completed']++;
            if (isset($e['record']['actual_turnout'])) $stats['turnout']+=(int)$e['record']['actual_turnout'];
        }
        return compact('now','today','first','month','view','status','search','scope','focus','start','end','events','matches','visible','cards','days','years','stats','invalid');
    }
}
