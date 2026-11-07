<!-- views/dashboard/index.php -->
<?php use App\Core\View; ?>

<!-- Emergency Alert Broadcast Banner (if any active alerts, visible to Resident only) -->
<?php if (!empty($alerts) && $role === 'Resident'): ?>
    <div style="margin-bottom: 20px;">
        <?php foreach ($alerts as $alt): ?>
            <div class="alert alert-danger" style="margin-bottom: 8px; border-left: 5px solid #b91c1c; display: flex; align-items: center; justify-content: space-between; gap: 12px; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.15);">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span style="display: inline-flex; width: 36px; height: 36px; border-radius: 50%; background: #fee2e2; color: #dc2626; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0;">
                        <i class="fa fa-triangle-exclamation"></i>
                    </span>
                    <div>
                        <strong style="font-size: 0.95rem; color: #991b1b;"><?= htmlspecialchars($alt['title']) ?></strong>
                        <div style="font-size: 0.85rem; color: #7f1d1d; margin-top: 2px;"><?= htmlspecialchars($alt['message']) ?></div>
                    </div>
                </div>
                <div style="font-size: 0.75rem; color: #991b1b; white-space: nowrap;">
                    <i class="fa fa-clock"></i> <?= date('M d, h:i A', strtotime($alt['created_at'])) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Header / Welcome & Quick Actions -->
<div class="page-heading welcome-panel">
    <div>
        <div class="dashboard-brand"><?php require __DIR__ . '/../components/brand.php'; ?></div>
        <div class="page-kicker">Your community, connected</div>
        <div class="page-actions" style="margin-top: 8px;">
            <h2 class="welcome-heading">
                Welcome, <?= htmlspecialchars($currentUser['firstname'] ?? 'neighbor') ?>.
            </h2>
            <span class="badge badge-primary" style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase;">
                <?= htmlspecialchars($role) ?> VIEW
            </span>
        </div>
        <p class="page-description">
            <?php if ($role === 'Resident'): ?>
                Check your relief updates, review community activities, and keep your household informed.
            <?php elseif ($role === 'Responder'): ?>
                Review your assigned operations and share clear, timely updates from the field.
            <?php elseif ($role === 'Barangay Head'): ?>
                Coordinate preparedness, review field reports, and manage community assistance.
            <?php else: ?>
                Monitor Hinaplanon's resources, relief claims, and disaster preparedness in one place.
            <?php endif; ?>
        </p>
    </div>

    <!-- Contextual Action Buttons -->
    <div class="page-actions">
        <?php if ($role === 'Resident'): ?>
            <a href="index.php?route=notifications" class="btn btn-outline">
                <i class="fa fa-hand-holding-heart"></i> Relief & Preparedness Hub
            </a>
            <a href="index.php?route=preparedness-calendar" class="btn btn-primary">
                <i class="fa fa-clock-rotate-left"></i> Event History
            </a>
        <?php elseif ($role === 'Responder'): ?>
            <a href="index.php?route=field-reports-create" class="btn btn-primary">
                <i class="fa fa-paper-plane"></i> Submit Ground Field Report
            </a>
            <a href="index.php?route=preparedness-calendar" class="btn btn-outline">
                <i class="fa fa-clock-rotate-left"></i> Event History
            </a>
        <?php elseif ($role === 'Barangay Head'): ?>
            <a href="index.php?route=hazard-map" class="btn btn-outline">
                <i class="fa fa-map-location-dot"></i> Live Hazard Map
            </a>
            <a href="index.php?route=disaster-requests-create" class="btn btn-primary">
                <i class="fa fa-file-circle-plus"></i> Fill Up Disaster Request Form
            </a>
            <a href="index.php?route=recommendations" class="btn btn-outline">
                <i class="fa fa-network-wired"></i> AI Priority Recommendations
            </a>
            <?php if (!empty($pendingResidents)): ?>
                <a href="index.php?route=pending-residents" class="btn btn-warning">
                    <i class="fa fa-users-gear"></i> Residents Account Management (<?= count($pendingResidents) ?>)
                </a>
            <?php else: ?>
                <a href="index.php?route=pending-residents" class="btn btn-outline">
                    <i class="fa fa-users-gear"></i> Residents Account Management
                </a>
            <?php endif; ?>
        <?php elseif ($role === 'ICDRRMO'): ?>
            <a href="index.php?route=hazard-map" class="btn btn-outline">
                <i class="fa fa-map-location-dot"></i> Live Hazard Map
            </a>
            <a href="index.php?route=disaster-requests" class="btn btn-primary">
                <i class="fa fa-file-circle-check"></i> Disaster Request Review
            </a>
            <a href="index.php?route=recommendations" class="btn btn-outline">
                <i class="fa fa-network-wired"></i> AI Priority Recommendations
            </a>
            <a href="index.php?route=users" class="btn btn-outline">
                <i class="fa fa-user-gear"></i> System User Accounts
            </a>
            <?php if (!empty($pendingResidents)): ?>
                <a href="index.php?route=pending-residents" class="btn btn-warning">
                    <i class="fa fa-user-clock"></i> Resident Approvals (<?= count($pendingResidents) ?>)
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ========================================== -->
<!-- 1. KEY PERFORMANCE INDICATOR (KPI) METRICS -->
<!-- ========================================== -->
<?php if ($role === 'Resident'): ?>
    <!-- Resident Personalized Metric Cards -->
    <div class="grid-cols-4" style="margin-bottom: 24px;">
        <div class="stat-card">
            <div class="stat-label"><i class="fa fa-bullhorn text-primary"></i> My Recorded Requests</div>
            <div class="stat-value"><?= count($myRequests) ?></div>
            <div class="stat-sub">Disaster relief assistance submitted</div>
        </div>

        <div class="stat-card">
            <div class="stat-label"><i class="fa fa-calendar-check text-success"></i> Community Drills</div>
            <div class="stat-value"><?= count($upcomingActivities) ?></div>
            <div class="stat-sub">Upcoming flood readiness drills & seminars</div>
        </div>

        <div class="stat-card">
            <div class="stat-label"><i class="fa fa-map-pin text-primary"></i> My Barangay Zone</div>
            <div class="stat-value" style="font-size: 1.25rem;">Barangay Hinaplanon</div>
            <div class="stat-sub">Your community in Iligan City</div>
        </div>

        <div class="stat-card">
            <div class="stat-label"><i class="fa fa-bell text-info"></i> Unread Notifications</div>
            <div class="stat-value"><?= (int)$unreadNotifs ?></div>
            <div class="stat-sub">Emergency advisories and updates</div>
        </div>
    </div>

<?php elseif ($role === 'Responder'): ?>
    <!-- Responder Operational Metric Cards -->
    <div class="grid-cols-4" style="margin-bottom: 24px;">
        <div class="stat-card">
            <div class="stat-label"><i class="fa fa-truck-medical text-primary"></i> Assigned Incidents</div>
            <div class="stat-value" style="color: <?= count($assignedIncidents) > 0 ? 'var(--primary)' : 'var(--text-muted)' ?>;">
                <?= count($assignedIncidents) ?>
            </div>
            <div class="stat-sub">Emergency responses awaiting your deployment</div>
        </div>

        <div class="stat-card">
            <div class="stat-label"><i class="fa fa-calendar-check text-success"></i> Preparedness Drills</div>
            <div class="stat-value"><?= count($upcomingActivities) ?></div>
            <div class="stat-sub">Scheduled community drills & readiness</div>
        </div>

        <div class="stat-card">
            <div class="stat-label"><i class="fa fa-file-waveform text-info"></i> Recent Field Reports</div>
            <div class="stat-value"><?= count($recentReports) ?></div>
            <div class="stat-sub">Road accessibility & ground assessments</div>
        </div>

        <div class="stat-card">
            <div class="stat-label"><i class="fa fa-triangle-exclamation text-danger"></i> High-Risk Puroks</div>
            <div class="stat-value" style="color: var(--danger);"><?= (int)($purokRiskSummary['High'] ?? 0) ?></div>
            <div class="stat-sub">Riverbanks & Mandulog flood zones</div>
        </div>
    </div>

<?php else: /* ICDRRMO & Barangay Head Command Cards */ ?>
    <!-- Command Staff System-Wide KPI Cards -->
    <div class="grid-cols-4" style="margin-bottom: 24px;">
        <div class="stat-card">
            <div class="stat-label"><i class="fa fa-triangle-exclamation text-danger"></i> Recorded Disaster Incidents</div>
            <div class="stat-value" style="color: <?= (int)$requestCounts['pending'] > 0 ? 'var(--danger)' : 'var(--primary-dark)' ?>;">
                <?= (int)$requestCounts['total'] ?>
            </div>
            <div class="stat-sub">
                <span class="badge badge-warning"><?= (int)$requestCounts['pending'] ?> Pending</span>
                <span class="badge badge-success"><?= (int)$requestCounts['approved'] ?> Handled</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-label"><i class="fa fa-users-gear text-primary"></i> <?= $role === 'Barangay Head' ? 'Residents Account Management' : 'Resident Approvals' ?></div>
            <div class="stat-value"><?= count($pendingResidents) ?></div>
            <div class="stat-sub">Resident registrations in review queue</div>
        </div>

        <div class="stat-card">
            <div class="stat-label"><i class="fa fa-boxes-stacked text-primary"></i> Remaining Relief Supplies</div>
            <div class="stat-value"><?= number_format((int)$inventorySummary['total_remaining']) ?></div>
            <div class="stat-sub">
                <?php if ((int)$inventorySummary['low_stock_count'] > 0): ?>
                    <span class="badge badge-danger"><?= (int)$inventorySummary['low_stock_count'] ?> Low Stock Alert</span>
                <?php else: ?>
                    <span class="badge badge-success">Stock Adequate</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-label"><i class="fa fa-map-location-dot text-danger"></i> Critical High-Risk Puroks</div>
            <div class="stat-value" style="color: #be123c;"><?= (int)($purokRiskSummary['High'] ?? 0) ?></div>
            <div class="stat-sub">Sendong flood-prone riverbank sectors</div>
        </div>
    </div>
<?php endif; ?>

<!-- ====================================================================== -->
<!-- 2. LIVE INTERACTIVE LEAFLET HAZARD MAP (Barangay Head & ICDRRMO only) -->
<!-- ====================================================================== -->
<?php if (in_array($role, ['Barangay Head', 'ICDRRMO'])): ?>
<section class="card" aria-labelledby="risk-overview-title">
    <div class="card-header"><h2 class="card-title" id="risk-overview-title"><i class="fa fa-chart-simple text-primary" aria-hidden="true"></i> Recorded purok risk overview</h2><span class="field-hint">Current risk classifications, not live weather predictions</span></div>
    <div class="card-body visual-summary">
        <?php $recordedPuroks = array_sum($purokRiskSummary); ?>
        <?php if ($recordedPuroks > 0): ?>
            <?php foreach ($purokRiskSummary as $riskLabel => $riskCount): ?>
                <div>
                    <div class="visual-summary-row"><span><?= htmlspecialchars($riskLabel) ?></span><strong><?= (int)$riskCount ?> purok<?= (int)$riskCount === 1 ? '' : 's' ?></strong></div>
                    <meter class="data-meter" min="0" max="<?= (int)$recordedPuroks ?>" value="<?= (int)$riskCount ?>" aria-label="<?= htmlspecialchars($riskLabel) ?>: <?= (int)$riskCount ?> of <?= (int)$recordedPuroks ?> recorded puroks"><?= (int)$riskCount ?> of <?= (int)$recordedPuroks ?></meter>
                </div>
            <?php endforeach; ?>
        <?php else: ?><p class="field-hint">No purok risk classifications are recorded yet.</p><?php endif; ?>
    </div>
</section>
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <div class="card-title" style="margin: 0;">
                <i class="fa fa-map-location-dot text-primary"></i> Live Disaster & Hazard Operations Map
            </div>
            <span class="badge badge-primary" style="font-size: 0.72rem;">
                <i class="fa fa-satellite-dish"></i> Live Polling (20s)
            </span>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
            <span style="font-size: 0.78rem; color: var(--text-muted);">
                Last synced: <span id="mapSyncTime" style="font-weight: 600;">Connecting...</span>
            </span>
            <button type="button" id="btnMapRefresh" class="btn btn-outline btn-sm" title="Refresh Map Data Now">
                <i class="fa fa-rotate"></i> Sync
            </button>
            <a href="index.php?route=hazard-map" class="btn btn-outline btn-sm">
                <i class="fa fa-expand"></i> Full Hazard Map
            </a>
        </div>
    </div>
    <div class="card-body" style="padding: 0;">
        <!-- Leaflet Map Container -->
        <div id="dashboardHazardMap" style="height: 440px; width: 100%; border-radius: 0 0 6px 6px;"></div>
    </div>
    <div class="card-footer" style="background: #f8fafc; padding: 10px 16px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; font-size: 0.8rem;">
        <div style="display: flex; gap: 14px; flex-wrap: wrap; align-items: center;">
            <span style="font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Map Legend:</span>
            <span style="display: inline-flex; align-items: center; gap: 4px;">
                <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background-color: var(--hazard-pink); border: 1px solid #fda4af;"></span>
                High Risk (Riverbank/Sendong)
            </span>
            <span style="display: inline-flex; align-items: center; gap: 4px;">
                <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background-color: var(--hazard-orange); border: 1px solid #fdba74;"></span>
                Medium Risk (Drainage Buffers)
            </span>
            <span style="display: inline-flex; align-items: center; gap: 4px;">
                <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background-color: var(--hazard-yellow); border: 1px solid #fef08a;"></span>
                Low Risk (Safe Zones)
            </span>
            <span style="display: inline-flex; align-items: center; gap: 4px;">
                <span style="display: inline-flex; width: 14px; height: 14px; border-radius: 3px; background-color: #dc2626; color: #fff; align-items: center; justify-content: center; font-size: 9px;"><i class="fa fa-triangle-exclamation"></i></span>
                Active Incident
            </span>
        </div>
        <div style="color: var(--text-muted);">
            Click on any hazard zone or incident marker to inspect details.
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================== -->
<!-- 3. ROLE-SPECIFIC TABLES & OPERATIONAL PANELS -->
<!-- ============================================== -->

<?php if ($role === 'Resident'): ?>
    <!-- Resident Specific Section: My Relief Requests & Preparedness Activities -->
    <div class="grid-cols-2">
        <!-- My Disaster Relief Requests -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fa fa-clock-rotate-left"></i> My Relief Requests</div>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Disaster Type</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($myRequests)): ?>
                            <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 24px;">You have no active relief requests logged.</td></tr>
                        <?php else: ?>
                            <?php foreach ($myRequests as $req): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($req['reference_no'] ?? ('#'.$req['disaster_request_id'])) ?></strong>
                                    </td>
                                    <td>
                                        <div><strong><?= htmlspecialchars($req['disaster_type']) ?></strong></div>
                                        <small style="color: var(--text-muted);"><?= htmlspecialchars($req['severity_level']) ?> Severity</small>
                                    </td>
                                    <td><?= date('M d, Y', strtotime($req['disaster_date'])) ?></td>
                                    <td><?= View::statusBadge($req['status']) ?></td>
                                    <td>
                                        <a href="index.php?route=disaster-requests-show&id=<?= $req['disaster_request_id'] ?>" class="btn btn-outline btn-sm">View Status</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Upcoming Community Drills & Preparedness Calendar -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fa fa-calendar-check text-primary"></i> Community Preparedness Drills</div>
                <a href="index.php?route=preparedness" class="btn btn-outline btn-sm"><i class="fa fa-calendar-days"></i> Full Calendar</a>
            </div>
            <div class="card-body">
                <?php if (empty($upcomingActivities)): ?>
                    <p style="color: var(--text-muted); font-size: 0.88rem; padding: 12px 0;">No preparedness drills scheduled at this time.</p>
                <?php else: ?>
                    <?php foreach (array_slice($upcomingActivities, 0, 4) as $act): ?>
                        <div style="padding: 10px 0; border-bottom: 1px solid var(--border-light);">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                <strong style="color: var(--primary-dark); font-size: 0.92rem;">
                                    <?= htmlspecialchars($act['title'] ?? 'Preparedness Activity') ?>
                                </strong>
                                <span class="badge badge-info"><?= htmlspecialchars($act['activity_type'] ?? 'Drill') ?></span>
                            </div>
                            <div style="font-size: 0.8rem; color: var(--text-muted);">
                                <i class="fa fa-calendar"></i> <?= date('M d, Y', strtotime($act['schedule_date'] ?? 'now')) ?> | 
                                <i class="fa fa-location-dot"></i> <?= htmlspecialchars($act['location'] ?? 'Barangay Hinaplanon') ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php elseif ($role === 'Responder'): ?>
    <!-- Responder Specific Section: Assigned Deployments & Verified Field Updates -->
    <div class="grid-cols-2">
        <!-- Assigned Deployments -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fa fa-person-running"></i> My Assigned Emergency Operations</div>
                <span class="badge badge-primary"><?= count($assignedIncidents) ?> Active</span>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Ref #</th>
                            <th>Purok & Risk</th>
                            <th>Type & Severity</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($assignedIncidents)): ?>
                            <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 24px;">No active operations currently assigned to you. Stand by for BDRRMC dispatch.</td></tr>
                        <?php else: ?>
                            <?php foreach ($assignedIncidents as $inc): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($inc['reference_no'] ?? ('#'.$inc['disaster_request_id'])) ?></strong>
                                        <?php if (!empty($inc['is_sos'])): ?><span class="badge badge-urgent">SOS</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <div><strong><?= htmlspecialchars($inc['purok_name']) ?></strong></div>
                                        <?= View::riskBadge($inc['purok_risk'] ?? 'Pending Verification') ?>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($inc['disaster_type']) ?></div>
                                        <small style="color: var(--text-muted);"><?= htmlspecialchars($inc['severity_level']) ?></small>
                                    </td>
                                    <td><?= View::statusBadge($inc['status']) ?></td>
                                    <td>
                                        <a href="index.php?route=disaster-requests-show&id=<?= $inc['disaster_request_id'] ?>" class="btn btn-primary btn-sm">
                                            <i class="fa fa-shield"></i> Respond
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Field Status Reports -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fa fa-road"></i> Ground Status & Road Passability</div>
                <a href="index.php?route=field-reports-create" class="btn btn-primary btn-sm"><i class="fa fa-plus"></i> Submit Report</a>
            </div>
            <div class="card-body">
                <?php if (empty($recentReports)): ?>
                    <p style="color: var(--text-muted); font-size: 0.88rem;">No ground status reports submitted yet.</p>
                <?php else: ?>
                    <?php foreach ($recentReports as $rep): ?>
                        <div style="padding: 10px 0; border-bottom: 1px solid var(--border-light);">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <strong style="color: var(--primary-dark);"><?= htmlspecialchars($rep['purok_name']) ?></strong>
                                <span class="badge badge-<?= $rep['road_accessibility'] === 'Passable' ? 'success' : 'danger' ?>">
                                    <i class="fa fa-car"></i> <?= htmlspecialchars($rep['road_accessibility']) ?>
                                </span>
                            </div>
                            <div style="font-size: 0.85rem; color: #334155; margin-top: 4px;">
                                <?= htmlspecialchars($rep['status_update']) ?>
                            </div>
                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">
                                By: <?= htmlspecialchars($rep['responder_name']) ?> | <?= date('M d, h:i A', strtotime($rep['report_time'])) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php else: /* ICDRRMO & Barangay Head Main Operational Split */ ?>
    <!-- Staff Section: Active Requests & AI Recommendation Engine Highlights -->
    <div class="grid-cols-2">
        <!-- Active Disaster Requests -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fa fa-triangle-exclamation"></i> Recent Disaster Requests & Incidents</div>
                <a href="index.php?route=disaster-requests" class="btn btn-outline btn-sm">View All (<?= (int)$requestCounts['total'] ?>)</a>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Ref / ID</th>
                            <th>Purok & Risk</th>
                            <th>Type & Severity</th>
                            <th>Households</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentRequests)): ?>
                            <tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 24px;">No disaster incidents logged in system.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentRequests as $req): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($req['reference_no'] ?? ('#'.$req['disaster_request_id'])) ?></strong>
                                        <?php if (!empty($req['is_sos'])): ?><span class="badge badge-urgent">SOS</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <div><strong><?= htmlspecialchars($req['purok_name']) ?></strong></div>
                                        <?= View::riskBadge($req['purok_risk'] ?? 'Pending Verification') ?>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($req['disaster_type']) ?></div>
                                        <small><?= htmlspecialchars($req['severity_level']) ?> <?= $req['is_urgent'] ? '<span class="badge badge-urgent">URGENT</span>' : '' ?></small>
                                    </td>
                                    <td><?= (int)$req['affected_households'] ?> HH</td>
                                    <td><?= View::statusBadge($req['status']) ?></td>
                                    <td>
                                        <a href="index.php?route=disaster-requests-show&id=<?= $req['disaster_request_id'] ?>" class="btn btn-outline btn-sm">
                                            Manage
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Decision Tree Recommendations -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fa fa-microchip"></i> Decision Tree Allocation Recommendations</div>
                <a href="index.php?route=recommendations" class="btn btn-outline btn-sm">All Recommendations</a>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Rec #</th>
                            <th>Purok</th>
                            <th>Priority</th>
                            <th>Recommended Qty</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentRecommendations)): ?>
                            <tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 24px;">No recommendations generated yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentRecommendations as $rec): ?>
                                <tr>
                                    <td><strong>#<?= $rec['recommendation_id'] ?></strong></td>
                                    <td>
                                        <strong><?= htmlspecialchars($rec['purok_name']) ?></strong>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($rec['supply_name'] ?? 'Relief Goods') ?></div>
                                    </td>
                                    <td><?= View::priorityBadge($rec['predicted_priority']) ?></td>
                                    <td>
                                        <strong style="color: var(--primary-dark); font-size: 1rem;"><?= (int)$rec['recommended_quantity'] ?></strong> 
                                        <small><?= htmlspecialchars($rec['unit_of_measure'] ?? 'units') ?></small>
                                    </td>
                                    <td><?= View::statusBadge($rec['recommendation_status']) ?></td>
                                    <td>
                                        <a href="index.php?route=recommendations-show&id=<?= $rec['recommendation_id'] ?>" class="btn btn-primary btn-sm">
                                            Review
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Responder Ground Field Reports (Administrative View) -->
        <div class="card" style="margin-top: 24px; grid-column: span 2;">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div class="card-title">
                    <i class="fa fa-truck-medical text-primary"></i> 
                    <?= $role === 'Barangay Head' ? 'Responder Ground Field Reports — Verification Queue' : 'Citywide Responder Ground Reports Monitoring' ?>
                </div>
                <div style="display: flex; gap: 8px;">
                    <a href="index.php?route=field-reports" class="btn btn-outline btn-sm">
                        <i class="fa fa-list"></i> View All Reports (<?= count($recentReports) ?>)
                    </a>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Report ID</th>
                            <th>Timestamp</th>
                            <th>Purok & Risk</th>
                            <th>Responder</th>
                            <th>Road Accessibility</th>
                            <th>Impact / Damages</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentReports)): ?>
                            <tr><td colspan="8" style="text-align: center; color: var(--text-muted); padding: 24px;">No responder field reports submitted yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentReports as $rep): ?>
                                <tr>
                                    <td><strong>FLD-<?= str_pad((string)$rep['report_id'], 3, '0', STR_PAD_LEFT) ?></strong></td>
                                    <td><?= date('M d, h:i A', strtotime($rep['report_time'])) ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($rep['purok_name']) ?></strong>
                                        <?= View::riskBadge($rep['purok_risk'] ?? 'Pending Verification') ?>
                                    </td>
                                    <td><?= htmlspecialchars($rep['responder_name']) ?></td>
                                    <td>
                                        <span class="badge badge-<?= $rep['road_accessibility'] === 'Passable' ? 'success' : 'danger' ?>">
                                            <?= htmlspecialchars($rep['road_accessibility']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        Houses: <?= (int)$rep['damaged_houses'] ?> | Casualties: <?= (int)$rep['casualties_count'] ?>
                                    </td>
                                    <td>
                                        <?php if (($rep['status'] ?? 'Submitted') === 'Verified'): ?>
                                            <span class="badge badge-success"><i class="fa fa-circle-check"></i> Verified</span>
                                        <?php elseif (($rep['status'] ?? 'Submitted') === 'Rejected'): ?>
                                            <span class="badge badge-danger"><i class="fa fa-circle-xmark"></i> Rejected</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning"><i class="fa fa-clock"></i> Pending Review</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($role === 'Barangay Head'): ?>
                                            <a href="index.php?route=field-reports-show&id=<?= $rep['report_id'] ?>" class="btn btn-primary btn-sm">
                                                <i class="fa fa-clipboard-check"></i> Review
                                            </a>
                                        <?php else: ?>
                                            <a href="index.php?route=field-reports-show&id=<?= $rep['report_id'] ?>" class="btn btn-outline btn-sm">
                                                <i class="fa fa-eye"></i> Monitor
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Relief Claims Monitoring & Oversight Section (ICDRRMO Super Admin) -->
        <?php if ($role === 'ICDRRMO'): ?>
        <div class="card" style="margin-top: 24px; grid-column: span 2; border-top: 4px solid var(--primary);">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div>
                    <div class="card-title">
                        <i class="fa fa-hand-holding-heart text-success"></i> 
                        Relief Claims Monitoring & Oversight
                    </div>
                    <small style="color: var(--text-muted); display: block; margin-top: 2px;">
                        Review relief claims, household priorities, and recorded distribution activity.
                    </small>
                </div>
                <div style="display: flex; gap: 8px;">
                    <a href="index.php?route=relief-claims-oversight" class="btn btn-primary btn-sm">
                        <i class="fa fa-shield-halved"></i> Open Oversight Console
                    </a>
                </div>
            </div>

            <!-- Quick Oversight Metrics Row -->
            <?php if (!empty($reliefStats)): ?>
            <div style="padding: 12px 18px; background: #f8fafc; border-bottom: 1px solid var(--border-light); display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                <span class="badge" style="background: #e2e8f0; color: #334155; font-size: 0.78rem; padding: 6px 12px;">
                    <i class="fa fa-users"></i> Total Beneficiaries: <strong><?= (int)$reliefStats['total'] ?></strong>
                </span>
                <span class="badge" style="background: #fef3c7; color: #b45309; border: 1px solid #fde68a; font-size: 0.78rem; padding: 6px 12px;">
                    <i class="fa fa-clock"></i> Pending: <strong><?= (int)$reliefStats['pending'] ?></strong>
                </span>
                <span class="badge" style="background: #e0e7ff; color: #4338ca; border: 1px solid #c7d2fe; font-size: 0.78rem; padding: 6px 12px;">
                    <i class="fa fa-shield-check"></i> Approved: <strong><?= (int)$reliefStats['approved'] ?></strong>
                </span>
                <span class="badge" style="background: #fef08a; color: #854d0e; border: 1px solid #fde047; font-size: 0.78rem; padding: 6px 12px;">
                    <i class="fa fa-box-open"></i> Ready: <strong><?= (int)$reliefStats['ready_to_claim'] ?></strong>
                </span>
                <span class="badge" style="background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; font-size: 0.78rem; padding: 6px 12px;">
                    <i class="fa fa-circle-check"></i> Claimed: <strong><?= (int)$reliefStats['claimed'] ?></strong>
                </span>
                <span class="badge" style="background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; font-size: 0.78rem; padding: 6px 12px;">
                    <i class="fa fa-triangle-exclamation"></i> High Priority: <strong><?= (int)$reliefStats['high_priority'] ?></strong>
                </span>
            </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Beneficiary / Resident</th>
                            <th>Purok & Risk</th>
                            <th>Priority Level</th>
                            <th>Allocated Aid</th>
                            <th>Assistance Status</th>
                            <th>Pickup Station</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentReliefClaims)): ?>
                            <tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 24px;">No relief claim records logged yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentReliefClaims as $c): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($c['firstname'] . ' ' . $c['lastname']) ?></strong>
                                        <div style="font-size: 0.75rem; color: var(--text-muted); font-family: monospace;">
                                            <?= htmlspecialchars($c['id_number'] ?? 'ID Pending') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($c['purok_name'] ?? 'Hinaplanon') ?></strong>
                                        <?= View::riskBadge($c['purok_risk'] ?? 'Pending Verification') ?>
                                    </td>
                                    <td><?= View::priorityBadge($c['priority_level']) ?></td>
                                    <td>
                                        <span style="font-weight: 600;"><?= (int)$c['allocated_quantity'] ?>x</span> 
                                        <small><?= htmlspecialchars($c['assistance_type'] ?? 'Food Pack') ?></small>
                                    </td>
                                    <td>
                                        <?php if ($c['assistance_status'] === 'Eligible'): ?>
                                            <span class="badge" style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; font-weight:700;"><i class="fa fa-circle-check"></i> Eligible</span>
                                        <?php elseif ($c['assistance_status'] === 'Approved'): ?>
                                            <span class="badge" style="background:#e0e7ff; color:#4338ca; border:1px solid #c7d2fe; font-weight:700;"><i class="fa fa-shield-check"></i> Approved</span>
                                        <?php elseif ($c['assistance_status'] === 'Ready to Claim'): ?>
                                            <span class="badge" style="background:#fef08a; color:#854d0e; border:1px solid #fde047; font-weight:700;"><i class="fa fa-box-open"></i> Ready to Claim</span>
                                        <?php elseif ($c['assistance_status'] === 'Claimed'): ?>
                                            <span class="badge" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-weight:700;"><i class="fa fa-box-archive"></i> Claimed</span>
                                        <?php elseif ($c['assistance_status'] === 'Not Eligible'): ?>
                                            <span class="badge" style="background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; font-weight:700;"><i class="fa fa-circle-xmark"></i> Not Eligible</span>
                                        <?php else: ?>
                                            <span class="badge" style="background:#fef3c7; color:#b45309; border:1px solid #fde68a; font-weight:700;"><i class="fa fa-clock"></i> Pending Review</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small style="color: var(--text-muted); display: block;">
                                            <i class="fa fa-location-dot"></i> <?= htmlspecialchars(mb_strimwidth($c['claiming_location'] ?? 'Hinaplanon Station', 0, 20, '...')) ?>
                                        </small>
                                        <small style="color: #64748b;">
                                            <?= !empty($c['claiming_date']) ? date('M d, Y', strtotime($c['claiming_date'])) : 'Pending' ?>
                                        </small>
                                    </td>
                                    <td>
                                        <a href="index.php?route=relief-claims-oversight" class="btn btn-outline btn-sm" title="View in Oversight Console">
                                            <i class="fa fa-eye"></i> Oversight
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Initialize Leaflet Hazard Map for Dashboard (Only for roles with active map) -->
<?php if (in_array($role, ['Barangay Head', 'ICDRRMO'])): ?>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof window.initHazardMap === 'function') {
            window.initHazardMap('dashboardHazardMap', { zoom: 14 });
        }
    });
</script>
<?php endif; ?>
