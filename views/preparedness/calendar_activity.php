<?php // Shared, server-escaped activity details, cloned into the existing modal by ID. ?>
<div class="card-header cal-section-heading"><span class="cal-status cal-<?= $ce($event['status_key']) ?>"><?= $ce($event['status_label']) ?></span><strong>Activity #<?= $event['id'] ?></strong></div>
<div class="card-body">
    <h3><?= $ce($recorded($act['title']??null)) ?></h3>
    <dl class="cal-detail-grid">
        <?php
        $details = [
            'Activity type'=>$act['activity_type']??null,
            'Scheduled date'=>$event['start']->format('l, F j, Y'),
            'Start time'=>$event['has_time']?$event['start']->format('g:i A').' (Asia/Manila)':null,
            'End date'=>$event['has_end']?$event['end']->format('F j, Y'):null,
            'End time'=>$event['has_end'] && strlen((string)$act['end_date'])>10?$event['end']->format('g:i A').' (Asia/Manila)':null,
            'Target purok'=>$act['purok_name']??null,
            'Location'=>$act['location']??null,
            'Participant capacity'=>$act['max_participants']??null,
            'Expected turnout'=>$act['expected_participants']??null,
            'Assigned personnel'=>$act['assigned_personnel']??null,
            'Volunteer team'=>$act['volunteer_person']??null,
            'Required resources'=>$act['required_resources']??null,
            'Allocated resources'=>$act['allocated_resources']??null,
            'Activity status'=>$act['status']??null,
        ];
        foreach ($details as $label=>$detail): ?><div><dt><?= $ce($label) ?></dt><dd><?= $ce($recorded($detail)) ?></dd></div><?php endforeach; ?>
    </dl>
    <h4>Description</h4><p class="cal-description"><?= nl2br($ce($recorded($act['description']??null))) ?></p>
    <?php if ($event['invalid_end']): ?><p class="alert alert-warning">The recorded end date is invalid or precedes the start. Only the valid start is displayed; no duration was inferred.</p><?php endif; ?>
    <?php if (!empty($act['evaluation_id'])): ?>
    <div class="cal-evaluation"><h4>Recorded post-activity evaluation</h4><dl class="cal-detail-grid"><?php foreach (['Actual turnout'=>'actual_turnout','Objectives rating (out of 5)'=>'objectives_met_rating','Supplies actually used'=>'supplies_used','Observations'=>'problems_encountered','Recommendations'=>'recommendations_for_improvement'] as $label=>$key): ?><div><dt><?= $ce($label) ?></dt><dd><?= $ce($recorded($act[$key]??null)) ?></dd></div><?php endforeach; ?></dl></div>
    <?php elseif ($canEvaluate && !in_array($event['status_key'],['cancelled','postponed'],true)): ?>
    <div class="page-actions"><a class="btn btn-outline btn-sm" href="index.php?route=preparedness-evaluate&amp;id=<?= $event['id'] ?>">Record Evaluation</a></div>
    <?php endif; ?>
</div>
