<?php
$esc = static fn($v) => htmlspecialchars((string)($v ?? 'Not Recorded'), ENT_QUOTES, 'UTF-8');
$value = static fn($v) => $v === null || $v === '' ? 'Not Recorded' : $v;
$admin = $overview['admin'];
$resident = $currentRole === 'Resident';
$tabs = ['events'=>'Historical & Current Disaster Events','analysis'=>'AI Affected Purok Analysis',
    'allocations'=>'Historical & Current Resource Allocation','relief'=>'Historical & Current Relief Goods Distribution',
    'activities'=>'Historical & Current Disaster Preparedness Activities'];
$query = is_string($_GET['search'] ?? null) ? substr(trim($_GET['search']), 0, 120) : '';
$from = is_string($_GET['from'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) ? $_GET['from'] : '';
$to = is_string($_GET['to'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']) ? $_GET['to'] : '';
$filter = static function (array $rows, string $dateKey) use ($query,$from,$to): array {
    return array_values(array_filter($rows, static function($r) use ($query,$from,$to,$dateKey) {
        $date = substr((string)($r[$dateKey] ?? ''), 0, 10);
        return ($query === '' || stripos(implode(' ', array_filter($r, 'is_scalar')), $query) !== false)
            && (!$from || $date >= $from) && (!$to || $date <= $to);
    }));
};
?>
<div class="page-heading"><div><div class="page-kicker">Padayon Hinaplanon</div><h2><?= $esc($title ?? 'Disaster Preparedness Management') ?></h2><p class="page-description">One connected view of recorded events, preparedness priorities, allocations, claims and activities.</p></div></div>
<nav class="preparedness-tabs" aria-label="Disaster preparedness sections">
<?php foreach ($tabs as $key=>$label): ?><a class="btn <?= $activeTab === $key ? 'btn-primary' : 'btn-outline' ?>" href="index.php?route=preparedness-calendar&amp;tab=<?= $key ?>" <?= $activeTab === $key ? 'aria-current="page"' : '' ?>><?= $esc($label) ?></a><?php endforeach; ?>
</nav>
<div class="alert alert-info"><div>Recorded history, verified system reports, hazard-map classifications and experimental predictions are different evidence types. None alone confirms a currently active disaster. Missing quantities are <strong>Not Recorded</strong>.</div></div>
<?php if ($activeTab !== 'activities'): ?>
<form method="get" class="card preparedness-filter">
    <input type="hidden" name="route" value="preparedness-calendar"><input type="hidden" name="tab" value="<?= $esc($activeTab) ?>">
    <label>Search<input class="form-control" name="search" value="<?= $esc($query) ?>" maxlength="120"></label>
    <label>From date<input class="form-control" type="date" name="from" value="<?= $esc($from) ?>"></label>
    <label>To date<input class="form-control" type="date" name="to" value="<?= $esc($to) ?>"></label>
    <button class="btn btn-primary">Filter</button>
</form>
<?php endif; ?>
<section aria-label="<?= $esc($tabs[$activeTab]) ?>">
<?php if ($activeTab === 'events'): ?>
    <div class="card"><h3>Historical source records</h3>
    <p>Historical record IDs identify source rows, not a verified cross-module event registry. No automatic event links have been inferred.</p>
    <?php if (!$resident): ?><div class="alert alert-warning"><div>The legacy TS Basyang import inferred population (beneficiaries × 4.2), severity, priority, requested and allocated quantities; its date was hard-coded to 2014-02-13. Those rows are quarantined from official conclusions despite their database verification flag. Confirm against original documents before use.</div></div><?php endif; ?>
    <div class="table-responsive"><table class="table"><thead><tr><th>Source row / recorded date</th><th>Event / purok</th><th>Evidence</th><th>Households / population</th><th>Severity / damage</th><th>Allocation / distribution</th></tr></thead><tbody>
    <?php $rows=$filter($overview['history'],'disaster_date'); foreach ($rows as $h): $trusted=\App\Models\PreparednessOverview::trustedHistory($h); ?>
    <tr><td>#<?= (int)$h['record_id'] ?><br><?= $esc($h['disaster_date']) ?></td><td><?= $esc($h['weather_condition']) ?><br><?= $esc($h['disaster_type']) ?> · <?= $esc($h['affected_purok']) ?></td><td><?= $trusted ? 'Recorded verified history (not independently authenticated)' : 'Needs source reconciliation — excluded from analysis' ?><br><small><?= $esc($h['data_source']) ?></small></td><td><?= $trusted ? $esc($value($h['affected_households'])) . ' / ' . $esc($value($h['affected_population'])) : 'Not Recorded (source-confirmed impact)' ?></td><td><?= $trusted ? $esc($value($h['severity_level'])) : 'Not Recorded (source-confirmed severity)' ?><br>Damage: Not Recorded</td><td>Not Recorded (source-linked quantities / units)</td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="6">No reconciled public historical records match these filters.</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <div class="card"><h3>Verified system incidents — not automatically current</h3>
    <?php if ($resident): ?><p>No incident-specific public release field exists. Unreleased operational reports are withheld. Public advisories remain available on <a href="index.php?route=home#alert">the public notices page</a>.</p><?php else: ?>
    <div class="table-responsive"><table class="table"><thead><tr><th>Incident ID / date</th><th>Type / purok</th><th>Status / verification</th><th>Recorded impact</th></tr></thead><tbody>
    <?php $rows=$filter($overview['events'],'disaster_date'); foreach ($rows as $e): ?><tr><td>#<?= (int)$e['disaster_request_id'] ?><br><?= $esc($e['disaster_date']) ?></td><td><?= $esc($e['disaster_type']) ?><br><?= $esc($e['purok_name']) ?></td><td><?= $esc($e['status']) ?><br>System verified <?= $esc($e['verified_at']) ?></td><td><?= $esc($value($e['affected_households'])) ?> households / <?= $esc($value($e['affected_population'])) ?> people<br><?= $esc($e['severity_level']) ?></td></tr><?php endforeach; if (!$rows): ?><tr><td colspan="4">No verified system incidents match.</td></tr><?php endif; ?>
    </tbody></table></div><p>These are application records, not independently authenticated official datasets. Dates and statuses must be reviewed before treating an incident as active.</p><?php endif; ?></div>
<?php elseif ($activeTab === 'analysis'): ?>
    <?php if ($resident): ?><div class="card"><h3>Publicly released affected-purok information</h3><p>No explicitly released assessment records are available. Internal predictions and calculations are withheld. A hazard-map risk classification does not confirm event-specific impact.</p></div><?php else: ?>
    <div class="card"><h3>Preparedness outlook by purok</h3><p>Experimental and unvalidated for operational accuracy. Map-only baseline rows are not machine-learning predictions. Quarantined imports and synthetic records are excluded. No “most affected purok” is declared without reconciled event-specific evidence.</p>
    <div class="table-responsive"><table class="table"><thead><tr><th>Planning order / purok</th><th>Hazard classification</th><th>Planning priority</th><th>Evidence / coverage</th><th>Explanation</th></tr></thead><tbody>
    <?php foreach ($analysis as $i=>$a): if ($query !== '' && stripos($a['purok_name'], $query) === false) continue; ?><tr><td><?= $i+1 ?>. <?= $esc($a['purok_name']) ?></td><td><?= $esc($a['risk']) ?><br><small><?= $esc($value($a['source_reference'])) ?></small></td><td><strong><?= $esc($a['priority']) ?></strong><br><?= $a['prediction'] ? 'Experimental Decision Tree' : 'Map-only baseline' ?></td><td><?= $a['history_count'] ?> reconciled historical rows<br><?= $a['verified_request_count'] ?> system-verified requests (all dates)<br><?= $a['activity_count'] ?> explicitly targeted activities</td><td><?= $esc($a['explanation']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><p>Planning order groups equal priorities by stable purok ID; it is not an impact ranking. Date filters apply to event lists, not undated map classifications. Activity counts are coverage indicators, not evidence of attendance or effectiveness.</p></div>
    <?php if ($admin): ?>
    <div class="card"><h3>Existing model provenance</h3><?php $modelFile=__DIR__.'/../../ml/data/active_model.json'; $meta=is_file($modelFile)?json_decode(file_get_contents($modelFile),true):[]; ?><p>Native artifact: <?= $esc($meta['algorithm'] ?? 'Unavailable') ?><br>SHA-256: <code><?= $esc(is_file($modelFile)?hash_file('sha256',$modelFile):null) ?></code><br>Artifact save date: <?= $esc($meta['saved_at'] ?? null) ?>. Verified training provenance and operational accuracy are not established by the artifact's existence.</p></div>
    <div class="card"><h3>Experimental FastAPI classification preview</h3><p>Uses the six recorded inputs from a verified system incident. The supplied API is a Random Forest candidate, separate from the native Decision Tree. Its current artifact was trained on Flood only and cannot predict the absent Low class. Confidence is a model-reported probability, not validated accuracy. Previewing never changes priorities, allocations, claim statuses or stock.</p>
    <form method="post" action="index.php?route=preparedness-predict">
        <input type="hidden" name="csrf_token" value="<?= $esc($csrfToken) ?>">
        <label for="prediction-incident">Verified system incident</label><select class="form-control" id="prediction-incident" name="disaster_request_id" required><option value="">Select an existing record</option><?php foreach ($overview['events'] as $e): ?><option value="<?= (int)$e['disaster_request_id'] ?>">#<?= (int)$e['disaster_request_id'] ?> · <?= $esc($e['disaster_date']) ?> · <?= $esc($e['purok_name']) ?></option><?php endforeach; ?></select>
        <label for="planning-resource">Optional resource for a separate quantity-policy preview</label><select class="form-control" id="planning-resource" name="supply_id"><option value="">No quantity preview</option><?php foreach ($overview['inventory'] as $s): ?><option value="<?= (int)$s['supply_id'] ?>"><?= $esc($s['supply_name']) ?> (<?= $esc($s['unit_of_measure']) ?>)</option><?php endforeach; ?></select>
        <label for="planning-rate">Reviewer-proposed units per household (1–100; not a verified need)</label><input class="form-control" id="planning-rate" type="number" name="units_per_household" min="1" max="100" step="1" value="1">
        <button class="btn btn-primary" style="margin-top:16px">Preview classification for human review</button>
    </form>
    <?php if ($previewError): ?><div class="alert alert-warning" role="status"><?= $esc($previewError) ?></div><?php endif; ?>
    <?php if ($preview && $preview['quantity_plan']): $plan=$preview['quantity_plan']; ?><div class="alert alert-info"><div><strong>Separate transparent quantity-policy preview — not approved or reserved</strong><br><?= $esc($plan['supply_name']) ?>: recorded households × reviewer-proposed <?= $plan['units_per_household'] ?> <?= $esc($plan['unit']) ?> per household.<br>Policy demand: <?= $plan['demand'] ?>; stock-constrained proposal: <?= $plan['proposed'] ?>; unmet policy demand: <?= $plan['unmet'] ?> <?= $esc($plan['unit']) ?>.<br>This is one incident/resource scenario, not simultaneous allocation across incidents or puroks. No stock is reserved; quantities must be revalidated in the existing approval workflow. Historical needs and activity requirements are incomplete.</div></div><?php endif; ?>
    <?php if ($preview && $preview['result']): $p=$preview['result']; ?><div class="alert alert-warning"><div><strong>Experimental, unvalidated preview — not approved</strong><br>Incident #<?= (int)$preview['request_id'] ?>: <?= $esc($p['predicted_priority']) ?>; model-reported confidence <?= $esc($p['confidence']) ?>%.<br>Model SHA-256: <code><?= $esc($p['model_sha256']) ?></code><br>Training date: Not Recorded in API metadata.</div></div>
    <dl><?php foreach ($p['probabilities'] as $label=>$prob): ?><dt><?= $esc($label) ?></dt><dd><?= $esc(round($prob*100,2)) ?>%</dd><?php endforeach; ?></dl>
    <details><summary>Recorded input features and compatibility warnings</summary><pre><?= $esc(json_encode($preview['features'],JSON_PRETTY_PRINT)) ?></pre><?php foreach ($p['feature_warnings'] as $w): ?><p><?= $esc($w) ?></p><?php endforeach; ?></details>
    <?php endif; ?></div>
    <?php endif; endif; ?>
<?php elseif ($activeTab === 'allocations'): ?>
    <div class="card"><h3>Existing allocation records</h3><p>Human authorization remains in the existing review workflow. Legacy review overwrites its quantity field; the original recommendation, approval and reservation cannot reliably be reconstructed. These states are not silently equated with distribution.</p>
    <?php if ($resident): ?><p>No explicitly released allocation schedules exist in this table. Internal inventory and unapproved allocations are withheld. Your approved claim schedule is available in the relief tab.</p><?php else: ?>
    <?php if ($admin): ?><a class="btn btn-outline" href="index.php?route=recommendations&amp;tab=incidents">Open existing authorized allocation review</a><?php endif; ?>
    <div class="table-responsive"><table class="table"><thead><tr><th>Allocation / incident IDs</th><th>Purok / resource</th><th>Status / recorded quantity</th><th>Recommended / approved / reserved / distributed</th></tr></thead><tbody>
    <?php $rows=$filter($overview['allocations'],'created_at'); foreach ($rows as $r): ?><tr><td>#<?= (int)$r['recommendation_id'] ?> / <?= $esc($value($r['disaster_request_id'])) ?></td><td><?= $esc($r['purok_name']) ?><br><?= $esc($value($r['supply_name'])) ?></td><td><?= $esc($r['recommendation_status']) ?><br><?= $esc($value($r['recommended_quantity'])) ?> <?= $esc($value($r['unit_of_measure'])) ?></td><td><?= in_array($r['recommendation_status'],['Generated','Pending'],true) ? $esc($value($r['recommended_quantity'])) . ' recommended' : 'Original recommendation: Not Recorded separately' ?><br>Approved: <?= in_array($r['recommendation_status'],['Approved','Modified'],true) ? $esc($value($r['recommended_quantity'])) . ' (legacy review field)' : 'Not Recorded' ?><br>Reserved / distributed: Not Recorded separately</td></tr><?php endforeach; if (!$rows): ?><tr><td colspan="4">No authorized allocation records match. Responders see only approved allocations on incidents assigned to them.</td></tr><?php endif; ?>
    </tbody></table></div><?php endif; ?></div>
    <?php if ($admin): ?><div class="card"><h3>Recorded inventory — not supplier receipts</h3><p>Stock is shown per resource and unit; unlike units are never added. Recorded opening quantities are not proof of goods received from a particular donor or event. Historical shortage and activity resource requirements: Not Recorded structurally.</p><div class="table-responsive"><table class="table"><thead><tr><th>Resource ID / category</th><th>Name / unit</th><th>Recorded quantity</th><th>Remaining stock</th></tr></thead><tbody><?php foreach ($overview['inventory'] as $s): ?><tr><td>#<?= (int)$s['supply_id'] ?> · <?= $esc($s['supply_type']) ?></td><td><?= $esc($s['supply_name']) ?> / <?= $esc($s['unit_of_measure']) ?></td><td><?= $esc($value($s['supply_quantity'])) ?></td><td><?= $esc($value($s['supply_remaining'])) ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<?php elseif ($activeTab === 'relief'): ?>
    <div class="card"><h3><?= $resident ? 'Your existing relief claim records' : 'Recorded relief distribution tracking' ?></h3><p>Live reads from the existing claim table; no duplicate claims are created. Disaster-event ID, supplier/donor receipts and event-specific requested quantities: <strong>Not Recorded</strong>. Claim allocation is not proof of receipt or stock reservation. “Claimed” is shown separately from recorded distribution time.</p>
    <?php if ($resident): ?><a class="btn btn-primary" href="index.php?route=notifications">Open Relief Goods Hub / Claim Ayuda</a><?php elseif ($admin): ?><a class="btn btn-outline" href="index.php?route=<?= $currentRole === 'ICDRRMO' ? 'relief-claims-oversight' : 'pending-residents' ?>">Open existing authorized claims workflow</a><?php endif; ?>
    <div class="table-responsive"><table class="table"><thead><tr><th>Claim ID / purok</th><th>Goods / allocated quantity</th><th>Claim status</th><th>Claim schedule</th><th>Recorded distributed / claimed</th></tr></thead><tbody>
    <?php $rows=$filter($overview['claims'],'claiming_date'); foreach ($rows as $r): ?><tr><td>#<?= (int)$r['beneficiary_id'] ?> · <?= $esc($r['purok_name']) ?></td><td><?= $esc($value($r['supply_name'])) ?><br><?= $esc($value($r['allocated_quantity'])) ?> <?= $esc($value($r['unit_of_measure'])) ?></td><td><?= $esc($r['assistance_status']) ?></td><td><?= in_array($r['assistance_status'],['Approved','Ready to Claim','Claimed'],true) ? $esc($value($r['claiming_date'])) . ' ' . $esc($value($r['claiming_time'])) . ' · ' . $esc($value($r['claiming_location'])) : 'No approved schedule released' ?></td><td>Distributed: <?= $esc($value($r['distributed_at'])) ?><br>Claimed: <?= $esc($value($r['claimed_at'])) ?></td></tr><?php endforeach; if (!$rows): ?><tr><td colspan="5">No authorized claim records match. Distribution assignments are not modeled for responders; other residents' records are withheld.</td></tr><?php endif; ?>
    </tbody></table></div></div>
<?php else: ?>
    <div class="alert alert-info"><div>Activities reuse the existing calendar, creation and evaluation workflows. Required resources, structured allocations, budget, disaster-event links and source-document attachments are Not Recorded in the activity schema. Maximum participants is capacity, not actual turnout.</div></div>
    <?php require __DIR__.'/calendar.php'; ?>
<?php endif; ?>
</section>
