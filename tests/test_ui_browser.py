"""Browser UI QA against a ui_sandbox.py copy ONLY. Requires Playwright + Chromium.
Usage: python tests/test_ui_browser.py --sandbox <temporary-copy> --url http://127.0.0.1:8011
"""
import argparse
import hashlib
import json
import re
import sqlite3
from contextlib import closing
from pathlib import Path
from urllib.request import urlopen

from playwright.sync_api import sync_playwright

ACCOUNTS = {
    'Resident': ('juan.delacruz@gmail.com', 'resident123'),
    'Responder': ('responder1@hinaplanon.gov.ph', 'responder123'),
    'Barangay Head': ('brgyhead@hinaplanon.gov.ph', 'brgyhead123'),
    'ICDRRMO': ('icdrrmo@iligan.gov.ph', 'icdrrmo123'),
}
NAV = {
    'Resident': {'dashboard', 'preparedness-calendar', 'notifications', 'profile'},
    'Responder': {'dashboard', 'field-reports', 'preparedness-calendar', 'reports', 'profile'},
    'Barangay Head': {'dashboard', 'hazard-map', 'preparedness-calendar', 'disaster-requests-create', 'reports', 'field-reports', 'pending-residents', 'profile'},
    'ICDRRMO': {'dashboard', 'hazard-map', 'preparedness-calendar', 'disaster-requests', 'reports', 'field-reports', 'users', 'pending-residents', 'relief-claims-oversight', 'profile'},
}


def check(condition, message):
    if not condition:
        raise AssertionError(message)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--sandbox', required=True, type=Path)
    parser.add_argument('--url', default='http://127.0.0.1:8011')
    args = parser.parse_args()
    sandbox = args.sandbox.resolve()
    check(sandbox.name.startswith('padayon-ui-') and (sandbox / 'qa-results.json').is_file(), 'A UI QA sandbox is required')
    check(args.url.startswith('http://127.0.0.1:') and ':8000' not in args.url, 'Use a separate loopback test port, not the working app')
    (sandbox / 'public/ui-qa-marker.txt').write_text(sandbox.name)
    with urlopen(args.url + '/ui-qa-marker.txt') as response:
        check(response.read().decode() == sandbox.name, 'HTTP server does not serve the specified sandbox')
    output = sandbox / 'browser-results'
    output.mkdir(exist_ok=True)
    failures, errors, passes = [], [], []
    def run(name, fn):
        try:
            fn()
            passes.append(name)
            print('PASS', name)
        except Exception as error:
            failures.append({'test': name, 'error': str(error)})
            print('FAILED', name, str(error)[:500])
    with sync_playwright() as pw:
        browser = pw.chromium.launch()
        context = browser.new_context(viewport={'width': 1440, 'height': 1000}, ignore_https_errors=True)
        page = context.new_page()
        page.on('pageerror', lambda error: errors.append(str(error)))
        def goto(route):
            response = page.goto(args.url + '/index.php?route=' + route, wait_until='networkidle')
            check(response.status < 400, f'HTTP {response.status}: {route}')
            text = page.locator('body').inner_text()
            check(not re.search(r'\b(?:Warning|Deprecated|Notice):\s+(?:Undefined|.*\(\):|Trying to|Attempt to)|Fatal error:', text), f'PHP warning/error rendered: {route}')
            check(page.locator('.field-error:visible').count() == 0, 'Unexpected initial field errors')
        def width_check():
            page.evaluate('() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))')
            page.wait_for_function("!document.querySelector('.main-content-wrapper') || !document.querySelector('.main-content-wrapper').getAnimations().some(a => a.playState === 'running')")
            overflow = page.evaluate('document.documentElement.scrollWidth - document.documentElement.clientWidth')
            if overflow > 2:
                offenders = page.evaluate('''() => [...document.querySelectorAll('body *')].filter(e => {
                    const r = e.getBoundingClientRect();
                    return r.width && r.right > innerWidth + 2 && !e.closest('.sidebar, .table-responsive, .calendar-scroll, .leaflet-container, .ui-dialog');
                }).slice(0, 6).map(e => ({tag:e.tagName, cls:e.className, width:Math.round(e.getBoundingClientRect().width)}))''')
                page.screenshot(path=output / ('overflow-' + str(len(failures)) + '.png'), full_page=True)
                raise AssertionError(f'Horizontal overflow {overflow}px at {page.viewport_size}; {offenders}')
        def login(role):
            goto('logout')
            goto('login')
            page.locator('#identifier').fill(ACCOUNTS[role][0])
            page.locator('#password').fill(ACCOUNTS[role][1])
            page.get_by_role('button', name='Log In', exact=True).click()
            page.wait_for_url('**route=dashboard')
            page.wait_for_load_state('networkidle')
            check(page.locator('.sidebar').count() == 1, 'Dashboard did not render')
        def auth():
            goto('login')
            page.locator('#togglePassword').click()
            check(page.locator('#password').get_attribute('type') == 'text', 'Password toggle failed')
            page.locator('#togglePassword').click()
            check(page.locator('#password').get_attribute('type') == 'password', 'Password hiding failed')
            page.get_by_role('button', name='Log In', exact=True).click()
            check(page.locator('[aria-invalid="true"]').count() > 0, 'Inline required validation missing')
            for route in ['login', 'register', 'forgot-password']:
                goto(route)
                for width in [1440, 768, 390, 320]:
                    page.set_viewport_size({'width': width, 'height': 900})
                    width_check()
                page.screenshot(path=output / (route + '-mobile.png'), full_page=True)
            page.set_viewport_size({'width': 1440, 'height': 1000})
        run('Authentication layouts, validation and password toggle', auth)
        def logos():
            source = Path(__file__).resolve().parents[1] / 'scratch/unpacked/HINAPLANON_BDRRMPlan_2024-2026/word/media/image2.png'
            target = sandbox / 'public/assets/images/barangay-hinaplanon-seal.png'
            check(hashlib.sha256(source.read_bytes()).digest() == hashlib.sha256(target.read_bytes()).digest(), 'Seal asset was altered')
            for route in ['home', 'login', 'register', 'forgot-password']:
                goto(route)
                check(page.locator('.barangay-seal').count() >= 2, f'Official branding missing from {route}')
                valid = page.locator('.barangay-seal').evaluate_all('''els => els.every(e => e.complete && e.naturalWidth > 0 &&
                    Math.abs(e.getBoundingClientRect().width - e.getBoundingClientRect().height) < 1 &&
                    getComputedStyle(e).objectFit === 'contain' && getComputedStyle(e).filter === 'none')''')
                check(valid, f'Seal missing, distorted, or filtered on {route}')
                check(not re.search(r'data-demo-|icdrrmo123|brgyhead123|responder123|resident123', page.content()), f'Public demo credentials on {route}')
        run('Exact official seal, proportions and no public quick-fill credentials', logos)
        def public_home():
            response = page.goto(args.url + '/', wait_until='networkidle')
            check(response.status == 200 and page.locator('#home-heading').count() == 1, 'Guest entry did not render public home')
            goto('home')
            sections = page.locator('#public-navigation [data-public-section]').evaluate_all('els => els.map(e => e.dataset.publicSection)')
            check(sections == ['home', 'alert', 'service', 'preparedness', 'evacuation', 'contact'], f'Public links are incorrect: {sections}')
            check(all(page.locator('#' + section).count() == 1 for section in sections), 'Public navigation has missing targets')
            for width in [1440, 1024, 768, 390, 320]:
                page.set_viewport_size({'width': width, 'height': 950})
                width_check()
                if width < 1024:
                    toggle = page.locator('#public-menu-toggle')
                    toggle.click()
                    check(page.locator('#public-navigation').is_visible(), 'Public mobile menu did not open')
                    width_check()
                    page.keyboard.press('Escape')
                    check(not page.locator('#public-navigation').is_visible(), 'Public mobile menu did not close')
                    check(toggle.evaluate('e => e === document.activeElement'), 'Public navigation did not restore focus')
            page.screenshot(path=output / 'public-home-mobile.png', full_page=True)
            page.set_viewport_size({'width': 1440, 'height': 1000})
            page.screenshot(path=output / 'public-home-desktop.png', full_page=True)
            response = page.goto(args.url + '/index.php?route=ui-qa-missing-page', wait_until='networkidle')
            check(response.status == 404 and page.locator('.public-header').count() == 1, 'Guest 404 did not retain public branding/status')
            goto('login')
            check(not page.locator('#loginPublicMap').is_visible(), 'Login map should be an optional reference')
            page.locator('.auth-map-disclosure > summary').click()
            page.wait_for_selector('#loginPublicMap .leaflet-overlay-pane path')
        run('Public homepage, anonymous entry, six section links, menu and login map', public_home)
        def notice_privacy():
            # Fixture records exist only in the verified temporary database.
            ids = []
            with closing(sqlite3.connect(sandbox / 'database/disaster_recommender.sqlite')) as db:
                user_id = db.execute('SELECT user_id FROM users WHERE email=?', (ACCOUNTS['Resident'][0],)).fetchone()[0]
                for user, role, title in [(None, 'All', 'UI-QA public notice'), (None, 'Resident', 'UI-QA private resident notice'), (user_id, None, 'UI-QA personal claim notice')]:
                    cursor = db.execute("INSERT INTO notifications (user_id,target_role,notification_type,title,message,priority,is_read,created_at) VALUES (?,?,'community_announcement',?,'UI QA fixture: <b>literal text</b>','Normal',0,CURRENT_TIMESTAMP)", (user, role, title))
                    ids.append(cursor.lastrowid)
                db.commit()
            try:
                goto('home')
                text = page.locator('#alert').inner_text()
                check('UI-QA public notice' in text, 'Public notice was not rendered')
                check('UI-QA private resident notice' not in text and 'UI-QA personal claim notice' not in text, 'Private notification was exposed')
                check('<b>literal text</b>' in text, 'Notice HTML was not escaped')
            finally:
                with closing(sqlite3.connect(sandbox / 'database/disaster_recommender.sqlite')) as db:
                    db.executemany('DELETE FROM notifications WHERE notification_id=?', [(id,) for id in ids])
                    db.commit()
        run('Public notices exclude personal/role messages and escape HTML', notice_privacy)
        for role in ACCOUNTS:
            run(role + ' login', lambda role=role: login(role))
            def nav(role=role):
                routes = page.locator('.sidebar-nav a').evaluate_all("els => els.map(e => new URL(e.href).searchParams.get('route'))")
                check(len(routes) == len(set(routes)) and set(routes) == NAV[role], f'Navigation changed: {routes}')
                check(page.locator('.sidebar-nav [aria-current="page"]').count() == 1, 'Active page not marked')
                check(page.locator('.sidebar .barangay-seal').count() == 1, 'Sidebar official logo missing')
                check(page.locator('.topbar .barangay-seal').count() == 1, 'Header official logo missing')
            run(role + ' navigation/permission link contract', nav)
            def dashboard(role=role):
                goto('dashboard')
                check(page.locator('.dashboard-brand .barangay-seal').count() == 1, 'Dashboard official logo missing')
                for width in [1440, 1024, 768, 390, 320]:
                    page.set_viewport_size({'width': width, 'height': 950})
                    width_check()
                page.screenshot(path=output / (role.replace(' ', '-') + '-mobile.png'), full_page=True)
                page.locator('#sidebar-toggle').click()
                check(page.locator('body').evaluate("e => e.classList.contains('nav-open')"), 'Mobile drawer failed')
                page.keyboard.press('Escape')
                check(page.locator('#sidebar-toggle').evaluate('e => e === document.activeElement'), 'Drawer did not restore focus')
                page.set_viewport_size({'width': 1440, 'height': 1000})
                page.locator('#sidebar-toggle').click()
                check(page.locator('body').evaluate("e => e.classList.contains('sidebar-collapsed')"), 'Desktop collapse failed')
                page.locator('#sidebar-toggle').click()
                page.screenshot(path=output / (role.replace(' ', '-') + '-desktop.png'), full_page=True)
            run(role + ' dashboard, five widths and navigation controls', dashboard)
            for route in sorted(NAV[role] - {'dashboard'}):
                def internal(route=route):
                    page.set_viewport_size({'width': 1440, 'height': 1000})
                    goto(route)
                    width_check()
                    for width in [768, 390, 320]:
                        page.set_viewport_size({'width': width, 'height': 900})
                        width_check()
                    page.set_viewport_size({'width': 1440, 'height': 1000})
                run(role + ' page ' + route, internal)
            def calendar():
                goto('preparedness-calendar')
                cell = page.locator('.cal-date-button').first
                cell.focus()
                page.keyboard.press('Enter')
                check(page.locator('.cal-modal-backdrop').evaluate("e => e.classList.contains('is-open')"), 'Calendar keyboard activation failed')
                page.keyboard.press('Escape')
                check(not page.locator('.cal-modal-backdrop').evaluate("e => e.classList.contains('is-open')"), 'Calendar Escape failed')
            run(role + ' calendar keyboard dialog', calendar)
            def preparedness():
                for tab in ['events','analysis','allocations','relief','activities']:
                    goto('preparedness-calendar&tab=' + tab)
                    check(page.locator('.preparedness-tabs a').count() == 5, 'Missing integrated sections')
                    check(page.locator('.sidebar a[href="index.php?route=preparedness-calendar"]').count() == 1, 'Duplicate preparedness sidebar entry')
                    check(page.locator('.sidebar a[href="index.php?route=recommendations"]').count() == 0, 'AI remains a standalone sidebar feature')
                    if role == 'Resident':
                        check(page.locator('#prediction-incident').count() == 0, 'Resident can access internal model inputs')
                        check('Recorded inventory — not supplier receipts' not in page.locator('main').inner_text(), 'Resident stock leak')
                    if role == 'Responder':
                        check(page.locator('#prediction-incident').count() == 0, 'Responder can submit assessment')
                    for width in [1440,390,320]:
                        page.set_viewport_size({'width':width,'height':950})
                        width_check()
                    page.set_viewport_size({'width':1440,'height':1000})
                if role in ['Barangay Head','ICDRRMO']:
                    goto('preparedness-calendar&tab=analysis')
                    token = page.locator('input[name="csrf_token"]').input_value()
                    selected = page.locator('#prediction-incident option').nth(1).get_attribute('value')
                    bad = page.request.post(args.url + '/index.php?route=preparedness-predict', form={'csrf_token':'invalid','disaster_request_id':selected})
                    check(bad.status == 403, 'Missing backend CSRF denial')
                    invalid = page.request.post(args.url + '/index.php?route=preparedness-predict', form={'csrf_token':token,'disaster_request_id':'-1'})
                    check(invalid.status == 422, 'Invalid event ID accepted')
                    page.locator('#prediction-incident').select_option(selected)
                    page.locator('#planning-resource').select_option(index=1)
                    page.get_by_role('button',name='Preview classification for human review').click()
                    page.wait_for_load_state('networkidle')
                    text=page.locator('main').inner_text()
                    check('Separate transparent quantity-policy preview' in text, 'Quantity policy preview missing')
                    check('model-reported confidence' in text or 'service disabled, unavailable' in text, 'Neither prediction nor graceful failure shown')
                else:
                    denied = page.request.post(args.url + '/index.php?route=preparedness-predict', form={'csrf_token':'invalid','disaster_request_id':'1'})
                    check('route=dashboard' in denied.url, 'Unauthorized role could invoke model preview')
            run(role + ' five preparedness tabs, privacy, responsive layouts and prediction guards', preparedness)
            def removed_routes():
                for route in ['emergency-sos', 'shelters', 'ml-model', 'historical-records', 'evaluation-results', 'audit-logs']:
                    goto(route)
                    check('route=dashboard' in page.url, f'Intentionally removed route was restored: {route}')
            run(role + ' intentionally removed routes remain denied over HTTP', removed_routes)
        def signed_in_public_scope():
            login('Resident')
            with page.expect_response(lambda response: 'route=api-map-data' in response.url) as map_response:
                goto('home')
            payload = map_response.value.json()
            check(payload['role'] == 'Public' and payload['is_auth'] is False and payload['incidents'] == [], 'Public map used signed-in incident scope')
            page.goto(args.url + '/', wait_until='networkidle')
            check(page.locator('.sidebar').count() == 1, 'Public map disturbed the authenticated session/default dashboard')
        run('Signed-in public map stays anonymous without changing login session', signed_in_public_scope)
        def tables_and_dialogs():
            login('ICDRRMO')
            goto('reports')
            search = page.locator('.table-tools input').first
            search.fill('UI-QA-no-such-record-000')
            check(page.locator('.empty-search:visible').count() > 0, 'Empty search state missing')
            search.fill('')
            goto('relief-claims-oversight')
            for trigger_name, modal_id in [('History', 'claimHistoryDetailModal'), ('Intervene', 'oversightInterventionModal')]:
                trigger = page.get_by_role('button', name=trigger_name, exact=True).first
                trigger.click()
                page.wait_for_function('(id) => document.getElementById(id).getAttribute("aria-hidden") === "false"', arg=modal_id)
                page.set_viewport_size({'width': 390, 'height': 740})
                check(page.locator('#' + modal_id + ' > div').evaluate('e => e.getBoundingClientRect().width <= innerWidth'), 'Dialog exceeds viewport')
                page.keyboard.press('Escape')
                check(trigger.evaluate('e => e === document.activeElement'), 'Dialog focus was not restored')
                page.set_viewport_size({'width': 1440, 'height': 1000})
            goto('recommendations')
            trigger = page.locator('[onclick^="openXaiModal"]').first
            trigger.click()
            page.wait_for_function('document.getElementById("xaiExplanationModal").getAttribute("aria-hidden") === "false"')
            page.keyboard.press('Escape')
            check(trigger.evaluate('e => e === document.activeElement'), 'XAI dialog focus was not restored')
        run('Table search/empty state and oversight/XAI dialog keyboard handling', tables_and_dialogs)
        def maps():
            goto('hazard-map')
            page.wait_for_selector('#interactiveHazardMap .leaflet-overlay-pane path')
            check('Recorded map information updated' in page.locator('.map-message').inner_text(), 'Map API did not update')
            page.route('**/*route=api-map-data', lambda route: route.fulfill(status=503, body='Service unavailable'))
            page.locator('#btnMapRefresh').click()
            page.wait_for_function('document.querySelector(".map-message").classList.contains("is-error")')
            check('could not be updated' in page.locator('.map-message').inner_text(), 'Map error message missing')
            check(not page.locator('#btnMapRefresh').is_disabled(), 'Map refresh stayed disabled after error')
            page.unroute('**/*route=api-map-data')
        run('Real Leaflet markers, map API loading and unavailable state', maps)
        def detail_and_create_pages():
            with closing(sqlite3.connect(sandbox / 'database/disaster_recommender.sqlite')) as db:
                rec = db.execute('SELECT recommendation_id FROM recommendation WHERE deleted_at IS NULL ORDER BY recommendation_id DESC LIMIT 1').fetchone()[0]
                assessment = db.execute('SELECT assessment_id FROM purok_assessments LIMIT 1').fetchone()[0]
                request = db.execute('SELECT disaster_request_id FROM disaster_request_form WHERE deleted_at IS NULL ORDER BY disaster_request_id DESC LIMIT 1').fetchone()[0]
            for route in ['users-create', 'inventory-create', 'preparedness', 'preparedness-create',
                          'disaster-requests', 'disaster-requests-show&id=' + str(request),
                          'recommendations-show&id=' + str(rec), 'recommendations-purok-show&id=' + str(assessment)]:
                goto(route)
                if route.startswith('disaster-requests-show'):
                    statuses = page.locator('.incident-history-entry .badge-success').all_text_contents()
                    check(all(status.strip() and status != 'Not recorded' for status in statuses), 'Recorded history statuses were not displayed')
                for width in [1440, 390, 320]:
                    page.set_viewport_size({'width': width, 'height': 900})
                    width_check()
                page.set_viewport_size({'width': 1440, 'height': 1000})
        run('Additional detail, creation and reporting layouts', detail_and_create_pages)
        # Actual field submission/review and claim requests use only the sandbox's demo records.
        def report_workflow():
            login('Responder')
            goto('field-reports-create')
            page.locator('[name="purok_id"]').select_option(index=1)
            page.locator('[name="status_update"]').fill('UI QA sandbox report: road observed passable; presentation workflow test only.')
            page.get_by_role('button', name='Submit Field Report', exact=True).click()
            page.wait_for_url('**route=field-reports')
            with closing(sqlite3.connect(sandbox / 'database/disaster_recommender.sqlite')) as db:
                report_id = db.execute("SELECT report_id FROM field_reports WHERE status_update LIKE 'UI QA sandbox report:%' ORDER BY report_id DESC").fetchone()[0]
            login('Barangay Head')
            goto('field-reports-show&id=' + str(report_id))
            page.get_by_role('button', name='Verify Ground Report', exact=True).click()
            page.wait_for_load_state('networkidle')
            with closing(sqlite3.connect(sandbox / 'database/disaster_recommender.sqlite')) as db:
                check(db.execute('SELECT status FROM field_reports WHERE report_id=?', (report_id,)).fetchone()[0] == 'Verified', 'Report was not verified')
        run('Actual field report submission and authorized verification', report_workflow)
        def claim_workflow():
            login('Resident')
            goto('notifications')  # Existing controller enrolls the demo account if needed.
            with closing(sqlite3.connect(sandbox / 'database/disaster_recommender.sqlite')) as db:
                db.execute("UPDATE relief_beneficiaries SET assistance_status='Eligible', is_approved=1 WHERE user_id=(SELECT user_id FROM users WHERE email=?)", (ACCOUNTS['Resident'][0],))
                db.commit()
            goto('notifications')
            trigger = page.get_by_role('button', name='Claim Ayuda', exact=True)
            trigger.click()
            page.wait_for_function("document.getElementById('claimAyudaModal').getAttribute('aria-hidden') === 'false'")
            check(page.locator('#claimAyudaModal').evaluate('e => e.contains(document.activeElement)'), 'Claim modal did not focus')
            page.keyboard.press('Escape')
            check(trigger.evaluate('e => e === document.activeElement'), 'Claim modal did not restore focus')
            trigger.click()
            page.locator('#confirmClaimCheckbox').check()
            page.get_by_role('button', name='Submit Claim Request', exact=True).click()
            page.wait_for_load_state('networkidle')
            with closing(sqlite3.connect(sandbox / 'database/disaster_recommender.sqlite')) as db:
                status = db.execute('SELECT assistance_status FROM relief_beneficiaries WHERE user_id=(SELECT user_id FROM users WHERE email=?)', (ACCOUNTS['Resident'][0],)).fetchone()[0]
                check(status == 'Pending', 'Claim request did not transition to Pending')
            check(page.get_by_role('button', name='Claim Ayuda', exact=True).count() == 0, 'Claim action remained visible after submission')
        run('Actual resident claim submission, dialog focus and duplicate-action state', claim_workflow)
        run('No browser JavaScript exceptions', lambda: check(not errors, str(errors)))
        browser.close()
    report = {'passes': passes, 'failures': failures, 'javascript_errors': errors}
    (output / 'results.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
    print(json.dumps({'passed': len(passes), 'failed': len(failures), 'artifacts': str(output)}, indent=2))
    return 1 if failures else 0


if __name__ == '__main__':
    raise SystemExit(main())
