<script>
(() => {
    if (window.ilbronzaTimetablesEditorBound) return;
    window.ilbronzaTimetablesEditorBound = true;

    const nextIndex = list => Math.max(-1, ...Array.from(list.children, item => Number(item.dataset.index))) + 1;
    const refreshEmpty = day => {
        if (day) day.querySelector('[data-empty-intervals]').hidden = day.querySelector('[data-interval-list]').children.length > 0;
    };

    document.addEventListener('click', event => {
        const button = event.target.closest('[data-timetable-add], [data-timetable-remove]');
        const form = button?.closest('[data-timetable-editor]');
        if (!form || button.disabled || button.closest('fieldset[disabled]')) return;
        event.preventDefault();

        if (button.dataset.timetableRemove) {
            const item = button.closest(`[data-${button.dataset.timetableRemove}]`);
            const day = item.closest('[data-day]');
            item.remove();
            if (button.dataset.timetableRemove === 'interval') refreshEmpty(day);
            return;
        }

        const type = button.dataset.timetableAdd;
        let list;
        const replacements = {};
        if (type === 'period') {
            list = form.querySelector('[data-period-list]');
            replacements.__P__ = nextIndex(list);
        } else if (type === 'exception') {
            const period = button.closest('[data-period]');
            list = period.querySelector('[data-exception-list]');
            replacements.__P__ = period.dataset.index;
            replacements.__E__ = nextIndex(list);
        } else {
            list = button.closest('[data-day]').querySelector('[data-interval-list]');
            replacements.__PREFIX__ = button.dataset.prefix;
            replacements.__I__ = nextIndex(list);
        }

        let html = form.querySelector(`[data-timetable-template="${type}"]`).innerHTML;
        for (const [placeholder, value] of Object.entries(replacements)) html = html.replaceAll(placeholder, String(value));
        const template = document.createElement('template');
        template.innerHTML = html;
        list.append(template.content.cloneNode(true));
        if (type === 'interval') refreshEmpty(button.closest('[data-day]'));
        list.lastElementChild.querySelector('input:not([type="hidden"])')?.focus();
    });

    document.addEventListener('submit', event => {
        const message = event.target.dataset.timetableConfirm;
        if (message && !window.confirm(message)) event.preventDefault();
    });
})();
</script>
