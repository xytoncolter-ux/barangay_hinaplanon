"""Calendar QA with fixture writes confined to a verified ui_sandbox.py copy."""
import argparse
import sqlite3
from contextlib import closing
from datetime import datetime, timedelta, timezone
from pathlib import Path
from urllib.parse import urlencode
from urllib.request import urlopen

from playwright.sync_api import sync_playwright

ACCOUNTS = {'Barangay Head':('brgyhead@hinaplanon.gov.ph','brgyhead123'),
            'ICDRRMO':('icdrrmo@iligan.gov.ph','icdrrmo123'),
            'Responder':('responder1@hinaplanon.gov.ph','responder123'),
            'Resident':('juan.delacruz@gmail.com','resident123')}


def require(condition, label):
    if not condition:
        raise AssertionError(label)


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--sandbox',required=True,type=Path)
    parser.add_argument('--url',required=True)
    args=parser.parse_args()
    sandbox=args.sandbox.resolve()
    require(sandbox.name.startswith('padayon-ui-') and (sandbox/'qa-results.json').is_file(),'Disposable QA copy required')
    require(args.url.startswith('http://127.0.0.1:') and ':8000' not in args.url,'Use separate QA port')
    (sandbox/'public/calendar-qa-marker.txt').write_text(sandbox.name)
    with urlopen(args.url+'/calendar-qa-marker.txt') as response:
        require(response.read().decode()==sandbox.name,'Server must serve the selected sandbox')
    now=datetime.now(timezone(timedelta(hours=8)))
    fixtures=[('Leap <img src=x onerror="window.calendarInjected=1">','2024-02-29 09:00:00','Completed',None),
              ('Same Day','2024-02-29 11:00:00','Scheduled',None),
              ('Spanning','2024-02-28 10:00:00','Completed','2024-03-02 16:00:00'),
              ('UTC','2024-02-29T20:00:00Z','Completed',None),
              ('Future',f'{now.year+2}-01-02 08:00:00','Scheduled',None),
              ('Ongoing','2026-11-06 10:00:00','Ongoing',None),
              ('Cancelled','2024-02-10 08:00:00','Cancelled',None),
              ('Postponed','2024-02-11 08:00:00','Postponed',None),
              ('Private Draft','2024-02-12 08:00:00','Draft',None),
              ('Missing Date','','Scheduled',None)]
    ids=[]
    dbpath=sandbox/'database/disaster_recommender.sqlite'
    with closing(sqlite3.connect(dbpath)) as db:
        # Test-only optional end field: production schema and records are never migrated.
        # Exercise defensive Draft/Postponed rendering even though today's live CHECK
        # permits only Scheduled/Ongoing/Completed/Cancelled. This pragma is connection-local.
        db.execute('pragma ignore_check_constraints=on')
        if 'end_date' not in [r[1] for r in db.execute('pragma table_info(preparedness_activities)')]:
            db.execute('alter table preparedness_activities add column end_date text')
        organizer=db.execute("select user_id from users where user_type='Barangay Head' and deleted_at is null limit 1").fetchone()[0]
        purok=db.execute('select purok_id from purok where barangay_id=1 and deleted_at is null limit 1').fetchone()[0]
        for title,date,status,end in fixtures:
            cursor=db.execute('''insert into preparedness_activities
                (user_id,barangay_id,activity_type,title,description,target_purok_id,schedule_date,location,status,max_participants,assigned_personnel,volunteer_person,end_date)
                values (?,1,'Training & Seminar',?,'QA fixture only',?,?,'QA venue',?,0,'','',?)''',
                (organizer,'Calendar QA '+title,purok,date,status,end))
            ids.append(cursor.lastrowid)
        db.commit()
    passed=[]
    try:
        with sync_playwright() as pw:
            browser=pw.chromium.launch()
            context=browser.new_context(viewport={'width':1440,'height':1000},timezone_id='America/Los_Angeles')
            page=context.new_page()
            errors=[]
            page.on('pageerror',lambda error:errors.append(str(error)))

            def go(**params):
                defaults=dict(route='preparedness-calendar',tab='activities',month='2024-02',cal_search='Calendar QA')
                defaults.update(params)
                response=page.goto(args.url+'/index.php?'+urlencode(defaults),wait_until='networkidle')
                require(response.status==200,'Calendar HTTP error')
                require('Fatal error' not in page.content() and 'Warning:' not in page.locator('body').inner_text(),'Rendered PHP warning')

            def login(role):
                page.goto(args.url+'/index.php?route=logout',wait_until='networkidle')
                page.goto(args.url+'/index.php?route=login',wait_until='networkidle')
                page.locator('#identifier').fill(ACCOUNTS[role][0]); page.locator('#password').fill(ACCOUNTS[role][1])
                page.get_by_role('button',name='Log In',exact=True).click(); page.wait_for_load_state('networkidle')

            login('Barangay Head'); go()
            require(page.locator('.cal-days > *').count()==35,'Leading/trailing grid alignment')
            require(page.locator('.cal-days > *').nth(4).get_attribute('data-date')=='2024-02-01','February starts in Thursday column')
            require(page.locator('#cal-day-2024-02-29 [data-calendar-event]').count()==3,'Same-day events not rendered individually')
            require(page.locator('#cal-day-2024-02-29 [data-calendar-event]:visible').count()==2,'Calendar does not limit visible events')
            more=page.locator('#cal-day-2024-02-29 [data-more-for]')
            require(more.inner_text()=='+1 more','Incorrect overflow count')
            more.click(); page.wait_for_selector('.cal-modal-backdrop.is-open')
            require(page.locator('#calModalBody .cal-detail-content').count()==3,'Overflow modal omits activities')
            page.keyboard.press('Escape')
            page.set_viewport_size({'width':320,'height':950})
            page.wait_for_function('document.querySelector("#cal-day-2024-02-29 [data-more-for]").textContent === "+2 more"')
            require(page.locator('#cal-day-2024-02-29 [data-calendar-event]:visible').count()==1,'Mobile calendar must show one event per day')
            require(more.inner_text()=='+2 more','Mobile overflow count not updated')
            more.click(); page.wait_for_selector('.cal-modal-backdrop.is-open')
            require(page.locator('#calModalBody .cal-detail-content').count()==3,'Mobile overflow loses records')
            page.keyboard.press('Escape')
            page.locator('#cal-period-title').click()
            require(page.locator('.cal-picker-panel').evaluate('e=>{const r=e.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth;}'),'Month dropdown exceeds mobile viewport')
            page.keyboard.press('Escape')
            require(page.locator('#cal-period-title').evaluate('e=>e===document.activeElement'),'Picker Escape did not restore heading focus')
            page.set_viewport_size({'width':1440,'height':1000})
            require(page.locator('#cal-day-2024-02-28 [data-calendar-event]').count()==1,'Multi-day range start missing')
            require(page.locator('#cal-day-2024-02-10 .cal-cancelled').count()==1,'Cancelled status color/label')
            require(page.locator('#cal-day-2024-02-11 .cal-postponed').count()==1,'Postponed status color/label')
            passed.append('Leap-year alignment, individual events, range and distinct statuses')
            trigger=page.locator(f'#cal-day-2024-02-29 [data-calendar-event="{ids[0]}"]')
            trigger.click(); page.wait_for_selector('.cal-modal-backdrop.is-open')
            require(page.locator('#calModalBody img').count()==0,'Activity text injected markup')
            text=page.locator('#calModalBody').inner_text()
            require('Assigned personnel\nNot recorded' in text and 'Required resources\nNot recorded' in text,'Missing optional fields fabricated')
            require('Participant capacity\n0' in text,'Recorded zero treated as missing')
            page.keyboard.press('Escape')
            page.wait_for_function('!document.querySelector(".cal-modal-backdrop").classList.contains("is-open")')
            require(trigger.evaluate('e=>e===document.activeElement'),'Event modal focus not restored')
            day=page.locator('#cal-day-2024-02-29 .cal-date-button'); day.focus(); page.keyboard.press('Enter')
            page.wait_for_selector('.cal-modal-backdrop.is-open')
            require(page.locator('#calModalBody .cal-detail-content').count()==3,'Day modal combines or omits activities')
            require('date=2024-02-29' in page.locator('#calModalFooterAction a').get_attribute('href'),'Existing authorized date-prefilled scheduling action missing')
            page.keyboard.press('Escape')
            passed.append('Individual/day modals, missing values, zero values, escaping and keyboard focus')
            page.locator('#cal-period-title').click()
            require(page.locator('[data-month-grid] a').count()==12,'Month picker must contain all 12 months')
            require(page.locator('[data-month-grid]').evaluate('e=>getComputedStyle(e).gridTemplateColumns.split(" ").length')==4,'Month grid must have four columns')
            first_month=page.locator('[data-month-grid] a').first
            first_month.focus();page.keyboard.press('ArrowDown')
            require(page.locator('[data-month-grid] a').nth(4).evaluate('e=>e===document.activeElement'),'Month picker keyboard grid navigation')
            page.locator('[data-picker-year]').click()
            require(page.locator('[data-year-grid] button').count()==12,'Year picker must have 12 years')
            target=page.locator(f'[data-picker-year-option="{now.year+2}"]')
            while not target.count():
                page.locator('[data-picker-next]').click()
            target.click()
            with page.expect_navigation(wait_until='networkidle'):
                page.locator('[data-picker-month="1"]').click()
            require(f'January {now.year+2}' in page.locator('#cal-period-title').inner_text(),'Automatic month/year navigation')
            page.locator('#cal-period-title').click(); page.locator('[data-picker-year]').click()
            while not page.locator('[data-picker-year-option="2011"]').count():
                page.locator('[data-picker-prev]').click()
            page.locator('[data-picker-year-option="2011"]').click()
            with page.expect_navigation(wait_until='networkidle'):
                page.locator('[data-picker-month="12"]').click()
            require('December 2011' in page.locator('#cal-period-title').inner_text(),'Direct historical month picker')
            require(page.locator('.cal-empty').count()>0,'Empty-month state missing')
            page.get_by_role('link',name='Next month',exact=True).click(); page.wait_for_load_state('networkidle')
            require('January 2012' in page.locator('#cal-period-title').inner_text(),'Next month does not cross years')
            page.get_by_role('link',name='Today',exact=True).click(); page.wait_for_load_state('networkidle')
            require(page.locator('#cal-picker').get_attribute('data-year')==str(now.year) and page.locator('#cal-picker').get_attribute('data-month')==str(now.month),'Today does not use Manila date')
            require(page.locator(f'#cal-day-{now:%Y-%m-%d}.is-today').count()==1,'Today outline missing')
            passed.append('Automatic selectors, direct picker, empty month, year boundary and Manila Today')
            go(cal_view='week',cal_date='2024-03-01')
            require(page.locator('.cal-days > *').count()==7,'Week must show seven weekdays')
            require(page.locator('#cal-day-2024-03-01 [data-calendar-event]').count()==2,'Week range/UTC conversion missing')
            for status,count in [('completed',3),('cancelled',1),('postponed',1),('ongoing',1),('upcoming',1)]:
                go(cal_view='list',cal_scope='all',cal_status=status)
                require(page.locator('.event-activity-card:not([hidden])').count()==count,'Wrong status filter: '+status)
            go(cal_view='list',cal_scope='all',cal_search='Calendar QA 2024')
            require(page.locator('.event-activity-card:not([hidden])').count()==7,'Full-archive year search')
            page.get_by_role('link',name='Clear filters',exact=True).click();page.wait_for_load_state('networkidle')
            require(page.locator('#cal-search').input_value()=='' and page.locator('#cal-status').input_value()=='all','Clear filters did not reset controls')
            passed.append('Week/list views, full-archive search and all status filters')
            for view in ['month','week','list']:
                go(cal_view=view,cal_scope='all')
                for width in [1440,768,390,320]:
                    page.set_viewport_size({'width':width,'height':950})
                    page.wait_for_timeout(250)  # Allow existing sidebar transition to settle.
                    require(page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),f'Overflow in {view} at {width}')
            page.set_viewport_size({'width':1440,'height':1000})
            passed.append('Three views at desktop, tablet and 390/320px mobile widths')
            for role in ACCOUNTS:
                login(role);go(cal_view='list',cal_scope='all')
                admin=role in ['Barangay Head','ICDRRMO']
                require(page.get_by_role('link',name='Schedule New Activity',exact=True).count()==int(admin),'Scheduling control role leak: '+role)
                if role=='Resident':
                    require('Calendar QA Private Draft' not in page.content(),'Resident received draft activity data')
                    require(page.locator('a[href*="preparedness-evaluate"]').count()==0,'Resident evaluation control leak')
                    for route in ['preparedness-create','preparedness-evaluate&id='+str(ids[0])]:
                        r=page.request.get(args.url+'/index.php?route='+route)
                        require('route=dashboard' in r.url,'Backend permission regression')
            passed.append('Four-role controls, resident draft-data exclusion and backend denials')
            # Browser timezone must not change the server's Manila day assignment.
            for zone in ['Asia/Tokyo','UTC']:
                other=browser.new_context(storage_state=context.storage_state(),timezone_id=zone)
                check_page=other.new_page()
                check_page.goto(args.url+'/index.php?'+urlencode(dict(route='preparedness-calendar',tab='activities',month='2024-03',cal_search='Calendar QA')),wait_until='networkidle')
                require(check_page.locator(f'#cal-day-2024-03-01 [data-calendar-event="{ids[3]}"]').count()==1,'Browser timezone shifted UTC input: '+zone)
                other.close()
            passed.append('Manila dates consistent in Los Angeles, Tokyo and UTC browser timezones')
            plain=browser.new_context(storage_state=context.storage_state(),java_script_enabled=False)
            plain_page=plain.new_page()
            plain_page.goto(args.url+'/index.php?route=preparedness-calendar&tab=activities&month=2026-11',wait_until='networkidle')
            plain_page.locator('#cal-period-title').click()
            plain_page.locator('.cal-picker-fallback input[name="cal_year"]').fill('2024')
            plain_page.locator('.cal-picker-fallback').get_by_role('button',name='Go',exact=True).click()
            plain_page.wait_for_load_state('networkidle')
            plain_page.locator('#cal-period-title').click()
            plain_page.locator('[data-picker-month="2"]').click()
            plain_page.wait_for_load_state('networkidle')
            require('February 2024' in plain_page.locator('#cal-period-title').inner_text(),'No-JavaScript navigation failed')
            require(plain_page.locator(f'#activity-card-{ids[0]}').is_visible(),'No-JavaScript activity details unavailable')
            plain.close()
            passed.append('Progressive enhancement: navigation and full details work without JavaScript')
            require(not errors,'Browser JS exceptions: '+str(errors))
            browser.close()
        for label in passed:
            print('PASS',label)
        print(f'Calendar browser groups: {len(passed)} passed')
    finally:
        with closing(sqlite3.connect(dbpath)) as db:
            db.executemany('delete from preparedness_activities where preparedness_activities_id=?',[(id,) for id in ids])
            db.commit()


if __name__=='__main__':
    main()
