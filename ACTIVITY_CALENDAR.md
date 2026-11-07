# Monthly Highlights & Activity Schedule improvements

## Minimalist calendar redesign (latest update)

The calendar now uses one compact toolbar: Today, Previous, Next, a clickable
Month/Year heading, and the preserved Month/Week/List switch. The heading opens a
**four-column × three-row month grid**. Clicking the year inside opens a matching
12-year grid with paging into past/future years (supported range 1–9999). Month/year
selection preserves filters and uses the existing server-side Manila date handling.
Escape, outside-click dismissal and arrow-key grid navigation are supported.

Duplicate date selectors, the redundant roster button and extra week-date form
were removed. One search bar and one status dropdown remain; an unobtrusive
**All dates** checkbox retains archive searching. Summary metrics and the status
guide are collapsible. Activity detail cards are collapsed in the default grid,
but remain accessible through their disclosure and automatically show for List View
or All dates searches. Full details still work without JavaScript.

The full-width seven-column grid has subtle shared borders, compact event labels,
navy text and a teal current-date marker. Two events per day appear on desktop and
one on narrow mobile screens. **+N more** opens all matching records for that day
in the existing modal; records are never discarded or combined. Full titles and
status labels remain in accessible link names and the details modal.

This follow-up changes only `views/preparedness/calendar.php`,
`public/assets/css/activity-calendar.css`, `public/assets/js/activity-calendar.js`,
`tests/test_activity_calendar_browser.py`, and this document. No backend permissions,
date calculations, database queries, records, model artifacts or schema were changed.
Checkpoint: `backup_pre_calendar_minimal_20261106_013033/calendar.zip`.

Follow-up verification: the extended **8 calendar browser groups passed**, including
the 4×3 pickers, year paging, keyboard navigation, 320px popup positioning, and
desktop/mobile +N overflow counts with all records available in the modal. The
**58 cross-role browser checks** also passed. The 43 pure calendar checks and focused
PHP suites remain passing; the same two unrelated Purok 6/8 assertions remain failing.

## Scope

The existing calendar inside Disaster Preparedness Management was improved in place.
There is no additional sidebar item, standalone calendar, endpoint, database table,
migration, import or automatic event creation. Existing activity IDs and the existing
`PreparednessActivity::all()` data source are preserved. The surrounding five-tab
interface, ML integration, dashboards, claims and authentication are unchanged.

Checkpoint before edits: `backup_pre_calendar_20261106_005507/calendar.zip`.

## Navigation and views

- Previous/Next and Today links retain filters. In Month/List views they step one
  month; in Week view they step one week. Year transitions are automatic.
- All 12 months appear in the heading's dropdown. A paged 12-year grid permits
  **1–9999**, avoiding repeated month-by-month navigation. Without JavaScript,
  the same dropdown provides server-generated month links and a year/Go fallback.
  Invalid query values fall back safely.
- Month view has a Sunday–Saturday grid, correct weekday placement, full month
  lengths, leading/trailing blanks, leap years and a separate teal Today outline.
- Week view shows Sunday–Saturday with its date range below the toolbar.
- List view shows the same activity IDs chronologically. Month/Week grids remain
  seven columns at mobile widths; List View is the expanded mobile-friendly alternative.
- Both server date calculations and explicit-offset timestamps use **Asia/Manila**.
  Naive database datetime values are interpreted as Manila local time. Date-only
  rows display no invented clock time. Browser-local date parsing is not used.

## Search and filters

Search supports title, purok, activity type, status, month names, year, ISO month
and ISO date. Multiple terms can be combined (for example `February 2024`).
Select **All dates (historical & future)** to search the complete authorized archive;
otherwise the list follows the displayed month/week. The grid always shows only
dates in its displayed period and applies the same search/status restrictions.

Filters: All Activities, Upcoming, Ongoing, Completed, Cancelled, Historical
Activities, Postponed and Urgent attention. Clear filters resets search/status/range
while retaining the selected period and view. Explicit status is not inferred from
elapsed time: an old Scheduled activity is **not** relabeled Completed or Ongoing.
Historical means the recorded date range ends before today's Manila civil date.
Date-only activities today are not falsely expired at an invented midnight time.

## Activity display, details and truthful data

- Each activity has its own title/status link, even when several share a date.
  Multiple evaluation joins are deduplicated by activity ID, retaining the latest
  evaluation; no record is deleted or merged in the database.
- Selecting an activity opens just that activity. Selecting a date opens its
  matching activities as separate details sections. Shared dialog accessibility
  retains Escape handling, keyboard focus trapping/restoration and inert background.
  Authorized officials retain the date-prefilled Schedule Activity action in day details.
- Server-rendered details include activity type, date/time, purok, venue,
  description, participant capacity, assignments, status and existing evaluation
  information. Unknown fields are **Not recorded**. Zero is retained as zero.
- Capacity is not presented as actual or expected turnout. Missing assignments are
  no longer replaced with invented BDRRMC officers or volunteer teams.
- Activity text is escaped server-side. The modal clones the already-escaped
  details rather than assembling database strings into HTML in JavaScript.
- Scheduled/upcoming is blue; Completed green; Ongoing orange; Cancelled gray with
  a dashed indicator; Postponed and explicit Urgent attention red with distinct
  labels/borders. Unknown or past-start Scheduled states are neutral, not green.
- Missing/invalid start dates are listed separately and never placed on a guessed
  calendar date. Invalid end dates do not create guessed durations.

## Database limitations / role permissions

The current schema stores **one `schedule_date` datetime**, not an end date/time,
structured required/allocated resources or expected turnout. These missing fields
are displayed as Not recorded. No duration is extracted from free-text descriptions
or draft plans. The read-only projector supports an explicitly supplied `end_date`
if a future approved data source provides one, including ranges across months;
the current source schema cannot persist multi-day ranges. Pure and browser tests
exercise that optional range contract with isolated fixtures only.

The database CHECK currently permits only Scheduled, Ongoing, Completed and
Cancelled. Postponed/Urgent rendering and filters do not add those values to the
live schema or create fake events. There are currently four recorded dated
activities (one Completed and three Scheduled) across October/November 2026.
No additional historical programs were inferred from disaster history or draft plans.

Existing permissions are preserved:

- Barangay Head/ICDRRMO retain the existing Schedule New Activity action.
- Barangay Head/ICDRRMO/Responder retain existing role-authorized evaluation actions.
  Evaluation authorization is currently role-level; assigned personnel are free
  text, not stable responder-user assignment IDs. This patch does not claim to
  implement new assignment-specific update permissions.
- Residents have no scheduling/evaluation controls and remain denied by the existing
  backend mutation routes. Draft/unknown activity states are withheld from their
  server-side activity projection, including JSON/detail content.
- The existing public lifecycle is published through authorized scheduling; there
  is no separate public-approval/visibility flag. This calendar does not add a new
  approval workflow or claim independent approval evidence that does not exist.
- No edit/cancel UI or resource-management actions were added: those activity routes
  do not currently exist. Adding them would be a separate permissions/workflow change.

## Files modified

- `src/Controllers/PreparednessController.php`: complete calendar archive retrieval
  without premature SQL status filtering; backend resident visibility projection.
- `views/preparedness/calendar.php`: existing component, controls, grid/views, filters,
  truthful metrics, activity list and existing modal container.
- `views/preparedness/calendar_activity.php` (new): reusable escaped activity details.
- `src/Support/ActivityCalendar.php` (new): timezone/date validation, deduplication,
  navigation state, status semantics, filtering and per-day projections; no writes.
- `public/assets/css/activity-calendar.css` (new): component-scoped styling.
- `public/assets/js/activity-calendar.js` (new): automatic navigation and modal
  enhancement, reusing shared dialog accessibility.
- `tests/test_activity_calendar.php` (new): pure read-only date/filter/range tests.
- `tests/test_activity_calendar_browser.py` (new): verified-sandbox-only browser QA.
- `tests/test_ui_browser.py`: focus the new semantic date link instead of a clickable div.
- `tests/ui_sandbox.py`: include the new pure calendar suite.
- This document.

## Running and verification

Use the existing PHP server and login, then open:

`http://127.0.0.1:8000/index.php?route=preparedness-calendar&tab=activities`

No database migration or ML service restart is required. Refresh the page to load
the component's new versioned stylesheet/script.

```powershell
# Pure tests; do not access or write the application database:
php tests/test_activity_calendar.php
# Existing PHP workflows use disposable copies through this runner:
python tests/ui_sandbox.py --label calendar
# Serve the returned sandbox on a separate loopback port, then run:
# <Playwright-python> tests/test_activity_calendar_browser.py --sandbox <copy> --url http://127.0.0.1:8014
# <Playwright-python> tests/test_ui_browser.py --sandbox <copy> --url http://127.0.0.1:8014
```

The dedicated browser suite verifies same-day events, leap-day placement, month/year
navigation, empty months, cross-year navigation, Today, week/list/status/search,
modal escaping/missing fields/zero values/keyboard focus, 1440/768/390/320px widths,
all four roles, and dates in Los Angeles/Tokyo/UTC browser timezones. Navigation
and full details are also tested without JavaScript. Fixtures and optional schema
extensions exist **only** in a verified temporary sandbox and fixture rows are removed
afterward; no live scheduling records are changed.

### Verification results (2026-11-06)

- **43 pure calendar checks passed** (including century leap-year rules, invalid
  dates/times, year boundaries, date-only schedules and duplicate evaluation joins).
- **8 dedicated calendar browser groups passed**, covering the scenarios above.
- **58 existing cross-role browser checks passed**, with no JavaScript exceptions.
- Focused PHP suites: **110 role / 22 field-report / 54 relief-hub / 24 oversight**
  assertions passed, plus the existing 14 integrated-preparedness checks.
- The route prober and edited PHP/JavaScript syntax checks passed. No PHP warnings,
  notices, deprecations or fatal errors appeared in the final QA server log.
- Two pre-existing main-suite Purok 6/8 data assertions still fail; map/risk data
  was not changed to make these unrelated assertions pass.
- Live activity records and model artifacts remain unchanged. A separate login
  audit entry appeared in the running application during QA and was left untouched.
