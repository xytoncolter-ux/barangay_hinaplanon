/* Enhancements for the existing PHP calendar; no browser Date parsing or additional API. */
(() => {
    function init() {
        const root = document.querySelector('.activity-calendar');
        if (!root) return;
        const modal = document.getElementById('dateDetailsModal');
        const body = document.getElementById('calModalBody');
        const heading = document.getElementById('calModalDateTitle');
        const footer = document.getElementById('calModalFooterAction');
        const days = JSON.parse(document.getElementById('calendar-day-data').textContent);
        const records = root.querySelector('.cal-records');
        if (root.dataset.view !== 'list' && root.dataset.scope !== 'all') records.open = false;
        const small = window.matchMedia('(max-width: 600px)');
        function fitEvents() {
            root.querySelectorAll('.cal-day-cell').forEach(cell => {
                const events = [...cell.querySelectorAll('[data-calendar-event]')];
                const limit = small.matches ? 1 : 2;
                events.forEach((event, index) => { event.hidden = index >= limit; });
                const more = cell.querySelector('[data-more-for]');
                const count = Math.max(0, events.length - limit);
                more.hidden = count === 0;
                more.textContent = '+' + count + ' more';
                more.setAttribute('aria-label', count + ' more activities on ' + cell.dataset.date + ' — view all ' + events.length);
            });
        }
        fitEvents();
        small.addEventListener('change', fitEvents);

        const picker = root.querySelector('#cal-picker');
        const monthGrid = picker.querySelector('[data-month-grid]');
        const yearGrid = picker.querySelector('[data-year-grid]');
        const yearButton = picker.querySelector('[data-picker-year]');
        const previous = picker.querySelector('[data-picker-prev]');
        const next = picker.querySelector('[data-picker-next]');
        const back = picker.querySelector('[data-picker-back]');
        const selectedYear = Number(picker.dataset.year);
        const selectedMonth = Number(picker.dataset.month);
        let pickerYear = selectedYear, yearMode = false;
        let yearPage = Math.min(9988, Math.floor((pickerYear - 1) / 12) * 12 + 1);
        picker.querySelector('.cal-picker-fallback').hidden = true;
        [yearButton, previous, next].forEach(button => { button.hidden = false; });
        function renderPicker() {
            monthGrid.hidden = yearMode;
            yearGrid.hidden = !yearMode;
            back.hidden = !yearMode;
            yearButton.textContent = yearMode ? yearPage + ' – ' + Math.min(9999, yearPage + 11) : String(pickerYear);
            yearButton.setAttribute('aria-label', yearMode ? 'Choose a year' : 'Choose year, ' + pickerYear);
            yearButton.setAttribute('aria-expanded', String(yearMode));
            previous.disabled = yearMode ? yearPage === 1 : pickerYear === 1;
            next.disabled = yearMode ? yearPage + 11 >= 9999 : pickerYear === 9999;
            previous.setAttribute('aria-label', yearMode ? 'Previous 12 years' : 'Previous year');
            next.setAttribute('aria-label', yearMode ? 'Next 12 years' : 'Next year');
            monthGrid.querySelectorAll('[data-picker-month]').forEach(link => {
                const month = Number(link.dataset.pickerMonth);
                const civilMonth = String(pickerYear).padStart(4, '0') + '-' + String(month).padStart(2, '0');
                const url = new URL(link.href);
                url.searchParams.set('month', civilMonth);
                url.searchParams.set('cal_date', civilMonth + '-01');
                link.href = url.href;
                link.setAttribute('aria-label', link.textContent + ' ' + pickerYear);
                if (pickerYear === selectedYear && month === selectedMonth) link.setAttribute('aria-current', 'date');
                else link.removeAttribute('aria-current');
            });
            yearGrid.replaceChildren();
            for (let year = yearPage; year <= Math.min(9999, yearPage + 11); year++) {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = String(year);
                button.dataset.pickerYearOption = String(year);
                button.setAttribute('aria-pressed', String(year === pickerYear));
                button.addEventListener('click', () => {
                    pickerYear = year; yearMode = false; renderPicker();
                    monthGrid.querySelector('[data-picker-month="' + selectedMonth + '"]').focus();
                });
                yearGrid.append(button);
            }
        }
        yearButton.addEventListener('click', () => {
            yearMode = !yearMode;
            yearPage = Math.min(9988, Math.floor((pickerYear - 1) / 12) * 12 + 1);
            renderPicker();
        });
        previous.addEventListener('click', () => {
            if (yearMode) yearPage = Math.max(1, yearPage - 12);
            else pickerYear = Math.max(1, pickerYear - 1);
            renderPicker();
        });
        next.addEventListener('click', () => {
            if (yearMode) yearPage = Math.min(9988, yearPage + 12);
            else pickerYear = Math.min(9999, pickerYear + 1);
            renderPicker();
        });
        back.addEventListener('click', () => { yearMode = false; renderPicker(); yearButton.focus(); });
        picker.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                event.preventDefault(); event.stopPropagation(); picker.open = false;
                picker.querySelector('summary').focus();
            }
            const grid = event.target.closest('.cal-picker-grid');
            if (!grid) return;
            const items = [...grid.querySelectorAll('a,button')];
            const index = items.indexOf(document.activeElement);
            let target = index;
            if (event.key === 'ArrowRight') target++;
            else if (event.key === 'ArrowLeft') target--;
            else if (event.key === 'ArrowDown') target += 4;
            else if (event.key === 'ArrowUp') target -= 4;
            else if (event.key === 'Home') target = 0;
            else if (event.key === 'End') target = items.length - 1;
            else return;
            event.preventDefault(); items[Math.max(0, Math.min(items.length - 1, target))].focus();
        });
        document.addEventListener('click', event => { if (picker.open && !event.composedPath().includes(picker)) picker.open = false; });
        picker.addEventListener('focusout', event => { if (event.relatedTarget && !picker.contains(event.relatedTarget)) picker.open = false; });
        renderPicker();
        let lastFocus = null;
        const detail = id => document.querySelector('#activity-card-' + Number(id) + ' .cal-detail-content');
        function show(title, ids, date = null) {
            lastFocus = document.activeElement;
            heading.textContent = title;
            body.replaceChildren();
            footer.replaceChildren();
            if (date && root.dataset.canSchedule === 'true') {
                const schedule = document.createElement('a');
                schedule.className = 'btn btn-primary btn-sm';
                schedule.href = 'index.php?route=preparedness-create&date=' + encodeURIComponent(date);
                schedule.textContent = 'Schedule activity on this date';
                footer.append(schedule);
            }
            ids.forEach(id => {
                const source = detail(id);
                if (source) body.append(source.cloneNode(true));
            });
            if (!body.children.length) {
                const empty = document.createElement('p');
                empty.className = 'cal-empty';
                empty.textContent = 'No matching activities are recorded for this date.';
                body.append(empty);
            }
            modal.classList.add('is-open');
            document.body.style.overflow = 'hidden';
        }
        function close() {
            modal.classList.remove('is-open');
            document.body.style.overflow = '';
            if (lastFocus?.isConnected) lastFocus.focus();
        }
        root.querySelectorAll('[data-calendar-event]').forEach(link => link.addEventListener('click', event => {
            event.preventDefault();
            const id = link.dataset.calendarEvent;
            const title = detail(id)?.querySelector('h3')?.textContent || 'Activity details';
            show(title, [id]);
        }));
        root.querySelectorAll('[data-calendar-day]').forEach(link => link.addEventListener('click', event => {
            event.preventDefault();
            const date = link.dataset.calendarDay;
            const day = days[date];
            if (day) show(day.label, day.ids, date);
        }));
        modal.querySelectorAll('[data-calendar-close]').forEach(button => button.addEventListener('click', close));
        modal.addEventListener('click', event => { if (event.target === modal) close(); });
        // Shared ui.js retains focus trapping, Escape handling, inert siblings and focus restoration.
        root.querySelectorAll('[data-calendar-auto]').forEach(form => {
            form.addEventListener('change', () => { if (form.reportValidity()) form.requestSubmit(); });
        });
        root.querySelectorAll('[data-auto-submit]').forEach(input => input.addEventListener('change', () => {
            if (input.form.reportValidity()) input.form.requestSubmit();
        }));
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
