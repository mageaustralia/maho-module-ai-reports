// Shared utilities - referenced by both the page-setup IIFE and the global aireportsSavedView.
var AiReportsUtil = (function () {
    'use strict';

    function postForm(url, payload) {
        var body = new URLSearchParams(payload);
        body.append('form_key', window.FORM_KEY || '');
        return fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: body,
            credentials: 'same-origin',
        }).then(function (r) { return r.json(); });
    }

    /**
     * Triggers a file download by building a temporary form and submitting it.
     * The browser handles the download natively without fetch+blob overhead.
     */
    function submitDownloadForm(url, payload) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = url;
        form.style.display = 'none';

        function addField(name, value) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        }

        addField('form_key', window.FORM_KEY || '');
        Object.entries(payload).forEach(function (pair) { addField(pair[0], pair[1]); });

        document.body.appendChild(form);
        form.submit();
        // Remove after a short delay to keep the DOM clean.
        setTimeout(function () { document.body.removeChild(form); }, 2000);
    }

    function renderLoading(target) {
        target.hidden = false;
        target.innerHTML =
            '<div class="aireports-loading">' +
                '<span class="aireports-spinner" aria-hidden="true"></span>' +
                '<span class="aireports-loading__text">Generating report</span>' +
                '<span class="aireports-loading__dots"><span></span><span></span><span></span></span>' +
            '</div>';
    }

    function renderError(target, msg) {
        target.hidden = false;
        target.innerHTML = '<div class="aireports-error">' + escapeHtml(msg || 'Unknown error') + '</div>';
    }

    function renderEnvelope(target, env, ctx) {
        target.hidden = false;
        target.innerHTML = '';
        var head = document.createElement('div');
        head.className = 'aireports-result__head';
        head.innerHTML = '<h4>' + escapeHtml(env.title) + '</h4>' +
                         '<p class="aireports-result__narrative">' + escapeHtml(env.narrative) + '</p>';
        target.appendChild(head);

        if (env.meta && env.meta.scope_warning) {
            var banner = document.createElement('div');
            banner.className = 'aireports-result__warning';
            banner.textContent = env.meta.scope_warning;
            target.appendChild(banner);
        }

        env.blocks.forEach(function (block) { target.appendChild(renderBlock(block)); });

        var actions = document.createElement('div');
        actions.className = 'aireports-result__actions';

        if (ctx.exportUrl && ctx.queryPlan) {
            var exportBtn = document.createElement('button');
            exportBtn.type = 'button';
            exportBtn.className = 'aireports-result__export';
            exportBtn.textContent = 'Export CSV';
            exportBtn.addEventListener('click', function () {
                submitDownloadForm(ctx.exportUrl, {
                    query_plan_json: JSON.stringify(ctx.queryPlan),
                });
            });
            actions.appendChild(exportBtn);
        }

        if (ctx.saveUrl && ctx.queryPlan) {
            var saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.className = 'aireports-result__save';
            saveBtn.textContent = 'Save report';
            saveBtn.addEventListener('click', async function () {
                var title = prompt('Title for this report?', env.title) || env.title;
                var data = await postForm(ctx.saveUrl, {
                    title: title,
                    query_plan_json: JSON.stringify(ctx.queryPlan),
                    render_hint_json: JSON.stringify(ctx.renderHint || {}),
                });
                if (data.success) { alert('Saved.'); } else { alert('Failed: ' + data.message); }
            });
            actions.appendChild(saveBtn);
        }

        if (actions.hasChildNodes()) {
            target.appendChild(actions);
        }
    }

    function renderBlock(block) {
        var el = document.createElement('div');
        el.className = 'aireports-block aireports-block--' + block.type;
        switch (block.type) {
            case 'kpi':   return renderKpi(el, block);
            case 'chart': return renderChart(el, block);
            case 'table': return renderTable(el, block);
        }
        el.textContent = JSON.stringify(block);
        return el;
    }

    function renderKpi(el, block) {
        el.innerHTML = '<div class="aireports-kpi__label">' + escapeHtml(block.label) + '</div>' +
                       '<div class="aireports-kpi__value">' + formatValue(block.value, block.format) + '</div>';
        return el;
    }

    function renderChart(el, block) {
        var canvas = document.createElement('canvas');
        el.appendChild(canvas);
        var isCategorical = block.chart_type === 'pie' || block.chart_type === 'doughnut';
        var cfg = {
            type: block.chart_type,
            data: {
                labels: block.x_axis,
                datasets: block.series.map(function (s, i) {
                    if (isCategorical) {
                        return {
                            label: s.name,
                            data: s.data,
                            backgroundColor: s.data.map(function (_, j) { return chartColor(j, 0.75); }),
                            borderColor: s.data.map(function (_, j) { return chartColor(j, 1.0); }),
                            borderWidth: 1,
                        };
                    }
                    return {
                        label: s.name,
                        data: s.data,
                        backgroundColor: chartColor(i, 0.6),
                        borderColor: chartColor(i, 1.0),
                        borderWidth: 1,
                    };
                }),
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: isCategorical
                            ? {
                                generateLabels: function (chart) {
                                    var data = chart.data;
                                    if (!data.labels || !data.labels.length || !data.datasets.length) return [];
                                    var ds = data.datasets[0];
                                    return data.labels.map(function (label, i) {
                                        return {
                                            text: String(label),
                                            fillStyle: Array.isArray(ds.backgroundColor) ? ds.backgroundColor[i] : ds.backgroundColor,
                                            strokeStyle: Array.isArray(ds.borderColor) ? ds.borderColor[i] : ds.borderColor,
                                            lineWidth: 1,
                                            index: i,
                                        };
                                    });
                                },
                            }
                            : undefined,
                    },
                },
            },
        };
        // eslint-disable-next-line no-new, no-undef
        new Chart(canvas.getContext('2d'), cfg);
        return el;
    }

    function renderTable(el, block) {
        var t = document.createElement('table');
        t.className = 'aireports-table data';
        var thead = document.createElement('thead');
        thead.innerHTML = '<tr>' + block.columns.map(function (c) { return '<th>' + escapeHtml(c.label) + '</th>'; }).join('') + '</tr>';
        t.appendChild(thead);
        var tbody = document.createElement('tbody');
        block.rows.forEach(function (row) {
            var tr = document.createElement('tr');
            block.columns.forEach(function (col) {
                var td = document.createElement('td');
                var value = row.cells[col.key];
                if (col.key === 'label' && row.link_url) {
                    td.innerHTML = '<a href="' + escapeHtml(row.link_url) + '">' + escapeHtml(String(value)) + '</a>';
                } else {
                    td.textContent = formatValue(value, col.format);
                }
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
        t.appendChild(tbody);
        el.appendChild(t);
        return el;
    }

    function chartColor(i, a) {
        var palette = ['#4F46E5', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#06B6D4'];
        var c = palette[i % palette.length];
        return c + Math.round(a * 255).toString(16).padStart(2, '0');
    }

    function formatValue(v, fmt) {
        if (v === null || v === undefined) return '-';
        if (fmt === 'integer') return Math.round(Number(v)).toLocaleString();
        if (fmt === 'currency') return Number(v).toLocaleString(undefined, { style: 'currency', currency: 'AUD' });
        if (fmt === 'number') {
            var n = Number(v);
            return Number.isInteger(n) ? n.toLocaleString() : n.toLocaleString(undefined, { maximumFractionDigits: 2 });
        }
        return String(v);
    }

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    return {
        postForm: postForm,
        submitDownloadForm: submitDownloadForm,
        renderLoading: renderLoading,
        renderError: renderError,
        renderEnvelope: renderEnvelope,
        escapeHtml: escapeHtml,
    };
})();

// Global object for the saved-report detail page. Methods called by framework button onclick attrs.
window.aireportsSavedView = (function () {
    'use strict';

    var root = null, ctx = null;

    function ensure() {
        if (root) return ctx;
        root = document.querySelector('.aireports-savedview');
        if (!root) return null;
        ctx = {
            id:          root.dataset.reportId,
            runUrl:      root.dataset.runUrl,
            exportUrl:   root.dataset.exportUrl,
            renameUrl:   root.dataset.renameUrl,
            deleteUrl:   root.dataset.deleteUrl,
            scheduleUrl: root.dataset.scheduleUrl,
            runLogUrl:   root.dataset.runLogUrl,
            backUrl:     root.dataset.backUrl,
            target:      root.querySelector('[data-aireports-result]'),
        };
        return ctx;
    }

    async function rerun() {
        var c = ensure();
        if (!c) return;
        AiReportsUtil.renderLoading(c.target);
        try {
            var data = await AiReportsUtil.postForm(c.runUrl, { id: c.id });
            if (!data.success) { AiReportsUtil.renderError(c.target, data.message); return; }
            AiReportsUtil.renderEnvelope(c.target, data.envelope, { saveUrl: null, exportUrl: null });
        } catch (err) {
            AiReportsUtil.renderError(c.target, err.message);
        }
    }

    function exportCsv() {
        var c = ensure();
        if (!c) return;
        AiReportsUtil.submitDownloadForm(c.exportUrl, { id: c.id });
    }

    async function rename() {
        var c = ensure();
        if (!c) return;
        var title = prompt('New title?');
        if (!title) return;
        await AiReportsUtil.postForm(c.renameUrl, { id: c.id, title: title });
        window.location.reload();
    }

    async function deleteReport() {
        var c = ensure();
        if (!c) return;
        if (!confirm('Delete this report?')) return;
        await AiReportsUtil.postForm(c.deleteUrl, { id: c.id });
        window.location.href = c.backUrl;
    }

    function openSchedule() {
        var c = ensure();
        if (!c) return;
        var scheduleSection = root.querySelector('[data-aireports-schedule]');
        if (!scheduleSection) return;
        var form = scheduleSection.querySelector('[data-schedule-form]');
        var summary = scheduleSection.querySelector('[data-schedule-summary]');
        if (!form || !summary) return;

        // Populate form with current values from data attributes.
        var enabledCb = form.querySelector('[data-schedule-enabled]');
        var cronInput = form.querySelector('[data-schedule-cron-expr]');
        var recipInput = form.querySelector('[data-email-recipients]');
        var prefixInput = form.querySelector('[data-email-subject-prefix]');

        if (enabledCb) enabledCb.checked = root.dataset.scheduleEnabled === '1';
        if (cronInput) cronInput.value = root.dataset.scheduleCronExpr || '';
        if (recipInput) recipInput.value = root.dataset.emailRecipients || '';
        if (prefixInput) prefixInput.value = root.dataset.emailSubjectPrefix || '';

        summary.style.display = 'none';
        form.style.display = '';
        form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function initScheduleCard() {
        var c = ensure();
        if (!c) return;
        var scheduleSection = root.querySelector('[data-aireports-schedule]');
        if (!scheduleSection) return;

        var form = scheduleSection.querySelector('[data-schedule-form]');
        var summary = scheduleSection.querySelector('[data-schedule-summary]');
        if (!form || !summary) return;

        // Render current state into summary.
        renderScheduleSummary(summary);

        // Save button
        var saveBtn = form.querySelector('[data-schedule-save]');
        if (saveBtn) {
            saveBtn.addEventListener('click', async function () {
                var enabledCb  = form.querySelector('[data-schedule-enabled]');
                var cronInput  = form.querySelector('[data-schedule-cron-expr]');
                var recipInput = form.querySelector('[data-email-recipients]');
                var prefixInput = form.querySelector('[data-email-subject-prefix]');

                var payload = {
                    id:                   c.id,
                    schedule_enabled:     enabledCb && enabledCb.checked ? '1' : '0',
                    schedule_cron_expr:   cronInput  ? cronInput.value.trim()  : '',
                    email_recipients:     recipInput  ? recipInput.value.trim()  : '',
                    email_subject_prefix: prefixInput ? prefixInput.value.trim() : '',
                };

                saveBtn.disabled = true;
                saveBtn.textContent = 'Saving...';
                try {
                    var data = await AiReportsUtil.postForm(c.scheduleUrl, payload);
                    if (!data.success) {
                        alert('Error: ' + data.message);
                        return;
                    }
                    // Update data attributes so summary re-renders correctly.
                    root.dataset.scheduleEnabled    = payload.schedule_enabled;
                    root.dataset.scheduleCronExpr   = payload.schedule_cron_expr;
                    root.dataset.emailRecipients    = payload.email_recipients;
                    root.dataset.emailSubjectPrefix = payload.email_subject_prefix;

                    form.style.display = 'none';
                    renderScheduleSummary(summary);
                    summary.style.display = '';
                } catch (err) {
                    alert('Error: ' + err.message);
                } finally {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save Schedule';
                }
            });
        }

        // Cancel button
        var cancelBtn = form.querySelector('[data-schedule-cancel]');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                form.style.display = 'none';
                summary.style.display = '';
            });
        }

        // Run log toggle
        var runlogToggle = scheduleSection.querySelector('[data-runlog-toggle]');
        var runlogBody   = scheduleSection.querySelector('[data-runlog-body]');
        var runlogOpen   = false;

        if (runlogToggle && runlogBody) {
            runlogToggle.addEventListener('click', async function () {
                runlogOpen = !runlogOpen;
                var arrow = runlogToggle.querySelector('[data-runlog-arrow]');
                if (arrow) arrow.innerHTML = runlogOpen ? '&#9650;' : '&#9660;';
                runlogBody.style.display = runlogOpen ? '' : 'none';
                if (runlogOpen && runlogBody.innerHTML === '') {
                    runlogBody.textContent = 'Loading...';
                    try {
                        var resp = await fetch(c.runLogUrl + '?id=' + encodeURIComponent(c.id), {
                            credentials: 'same-origin',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        });
                        var data = await resp.json();
                        if (data.success && data.logs) {
                            runlogBody.innerHTML = renderRunLog(data.logs);
                        } else {
                            runlogBody.textContent = data.message || 'No log data.';
                        }
                    } catch (err) {
                        runlogBody.textContent = 'Failed to load run history.';
                    }
                }
            });
        }
    }

    function renderScheduleSummary(el) {
        var enabled  = root.dataset.scheduleEnabled === '1';
        var cronExpr = root.dataset.scheduleCronExpr || '';
        var recips   = root.dataset.emailRecipients || '';
        var prefix   = root.dataset.emailSubjectPrefix || '';

        if (!cronExpr) {
            el.innerHTML = '<p class="aireports-schedule__none">No schedule configured. Click <strong>Schedule</strong> to set one up.</p>';
            return;
        }

        var statusBadge = enabled
            ? '<span class="aireports-schedule__badge aireports-schedule__badge--on">Enabled</span>'
            : '<span class="aireports-schedule__badge aireports-schedule__badge--off">Disabled</span>';

        var html = '<div class="aireports-schedule__row">'
            + statusBadge
            + ' <code>' + AiReportsUtil.escapeHtml(cronExpr) + '</code>';
        if (recips) {
            html += ' &rarr; ' + AiReportsUtil.escapeHtml(recips);
        }
        if (prefix) {
            html += ' (prefix: <em>' + AiReportsUtil.escapeHtml(prefix) + '</em>)';
        }
        html += '</div>';
        el.innerHTML = html;
    }

    function renderRunLog(logs) {
        if (!logs || !logs.length) {
            return '<p class="aireports-runlog__empty">No runs recorded yet.</p>';
        }
        var html = '<table class="aireports-table data aireports-runlog__table">'
            + '<thead><tr>'
            + '<th>Started</th><th>Trigger</th><th>Status</th>'
            + '<th>Rows</th><th>Ms</th><th>Emailed to</th><th>Error</th>'
            + '</tr></thead><tbody>';

        logs.forEach(function (log) {
            var statusClass = log.status === 'success' ? 'aireports-runlog__ok' : 'aireports-runlog__err';
            html += '<tr>'
                + '<td>' + AiReportsUtil.escapeHtml(log.started_at || '') + '</td>'
                + '<td>' + AiReportsUtil.escapeHtml(log.triggered_by || '') + '</td>'
                + '<td class="' + statusClass + '">' + AiReportsUtil.escapeHtml(log.status || '') + '</td>'
                + '<td>' + (log.row_count || 0) + '</td>'
                + '<td>' + (log.elapsed_ms || 0) + '</td>'
                + '<td>' + AiReportsUtil.escapeHtml(log.email_sent_to || '-') + '</td>'
                + '<td>' + AiReportsUtil.escapeHtml(log.error_message || '') + '</td>'
                + '</tr>';
        });

        html += '</tbody></table>';
        return html;
    }

    return { rerun: rerun, exportCsv: exportCsv, rename: rename, deleteReport: deleteReport, openSchedule: openSchedule, initScheduleCard: initScheduleCard };
})();

// Page-specific setup (ask page, saved list page).
(function () {
    'use strict';

    function init() {
        document.querySelectorAll('.aireports-ask').forEach(setupAsk);
        document.querySelectorAll('.aireports-saved').forEach(setupSaved);
        // aireportsSavedView global handles the saved-view detail page; auto-run on load.
        if (document.querySelector('.aireports-savedview')) {
            aireportsSavedView.rerun();
            aireportsSavedView.initScheduleCard();
        }
    }

    function setupAsk(root) {
        var form = root.querySelector('[data-aireports-form]');
        var input = root.querySelector('[data-aireports-input]');
        var result = root.querySelector('[data-aireports-result]');
        var generateUrl = root.dataset.generateUrl;
        var saveUrl = root.dataset.saveUrl;
        var exportUrl = root.dataset.exportUrl;

        root.querySelectorAll('[data-aireports-chip]').forEach(function (chip) {
            chip.addEventListener('click', function () {
                input.value = chip.dataset.prompt;
                form.requestSubmit();
            });
        });

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            if (!input.value.trim()) return;
            AiReportsUtil.renderLoading(result);
            try {
                var data = await AiReportsUtil.postForm(generateUrl, { q: input.value });
                if (!data.success) { AiReportsUtil.renderError(result, data.message); return; }
                AiReportsUtil.renderEnvelope(result, data.envelope, {
                    saveUrl: saveUrl,
                    exportUrl: exportUrl,
                    queryPlan: data.query_plan,
                    renderHint: data.render_hint,
                });
            } catch (err) {
                AiReportsUtil.renderError(result, err.message);
            }
        });
    }

    function setupSaved(root) {
        var renameUrl = root.dataset.renameUrl;
        var deleteUrl = root.dataset.deleteUrl;
        var exportUrl = root.dataset.exportUrl;

        root.querySelectorAll('[data-aireports-export]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var row = btn.closest('tr');
                var id = row.dataset.reportId;
                AiReportsUtil.submitDownloadForm(exportUrl, { id: id });
            });
        });

        root.querySelectorAll('[data-aireports-rename]').forEach(function (btn) {
            btn.addEventListener('click', async function () {
                var row = btn.closest('tr');
                var id = row.dataset.reportId;
                var title = prompt('New title?');
                if (!title) return;
                await AiReportsUtil.postForm(renameUrl, { id: id, title: title });
                window.location.reload();
            });
        });

        root.querySelectorAll('[data-aireports-delete]').forEach(function (btn) {
            btn.addEventListener('click', async function () {
                var row = btn.closest('tr');
                var id = row.dataset.reportId;
                if (!confirm('Delete this report?')) return;
                await AiReportsUtil.postForm(deleteUrl, { id: id });
                window.location.reload();
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
