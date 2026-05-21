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

    function renderEnvelope(target, env, ctx, drillUrl) {
        target.hidden = false;
        target.innerHTML = '';
        var head = document.createElement('div');
        head.className = 'aireports-result__head';
        var headHtml = '<h4>' + escapeHtml(env.title) + '</h4>';
        if (env.meta && env.meta.period && env.meta.period.label) {
            headHtml += '<p class="aireports-result__period">Period: ' + escapeHtml(env.meta.period.label) + '</p>';
        }
        headHtml += '<p class="aireports-result__narrative">' + escapeHtml(env.narrative) + '</p>';
        head.innerHTML = headHtml;
        target.appendChild(head);

        if (env.meta && env.meta.scope_warning) {
            var banner = document.createElement('div');
            banner.className = 'aireports-result__warning';
            banner.textContent = env.meta.scope_warning;
            target.appendChild(banner);
        }

        // Suppress per-row drill chevrons for primitives that don't support drilldown.
        var effectiveDrillUrl = (env.meta && env.meta.supports_drilldown === false) ? null : drillUrl;
        env.blocks.forEach(function (block) { target.appendChild(renderBlock(block, ctx, effectiveDrillUrl)); });

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

    function renderBlock(block, ctx, drillUrl) {
        var el = document.createElement('div');
        el.className = 'aireports-block aireports-block--' + block.type;
        switch (block.type) {
            case 'kpi':   return renderKpi(el, block);
            case 'chart': return renderChart(el, block);
            case 'table': return renderTable(el, block, ctx, drillUrl);
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

    function renderTable(el, block, ctx, drillUrl) {
        var canDrill = !!(drillUrl && ctx && ctx.queryPlan);
        var t = document.createElement('table');
        t.className = 'aireports-table data';
        var thead = document.createElement('thead');
        var thRow = '<tr>';
        if (canDrill) thRow += '<th class="aireports-table__chevron"></th>';
        thRow += block.columns.map(function (c) { return '<th>' + escapeHtml(c.label) + '</th>'; }).join('') + '</tr>';
        thead.innerHTML = thRow;
        t.appendChild(thead);
        var tbody = document.createElement('tbody');
        var colSpan = block.columns.length + (canDrill ? 1 : 0);
        block.rows.forEach(function (row) {
            var tr = document.createElement('tr');
            if (canDrill) {
                var chevronTd = document.createElement('td');
                chevronTd.className = 'aireports-table__chevron';
                var btn = document.createElement('button');
                btn.setAttribute('data-aireports-drill', '');
                btn.setAttribute('aria-label', 'Expand row');
                btn.textContent = '▸'; // right-pointing triangle
                chevronTd.appendChild(btn);
                tr.appendChild(chevronTd);

                // Drill row (initially hidden, inserted after this tr).
                var drillTr = document.createElement('tr');
                drillTr.className = 'aireports-drill-row';
                drillTr.hidden = true;
                var drillTd = document.createElement('td');
                drillTd.colSpan = colSpan;
                drillTr.appendChild(drillTd);

                (function (capturedRow, capturedDrillTr, capturedDrillTd, capturedBtn) {
                    btn.addEventListener('click', function () {
                        var isExpanded = capturedBtn.classList.contains('expanded');
                        if (isExpanded) {
                            capturedBtn.classList.remove('expanded');
                            capturedDrillTr.hidden = true;
                            return;
                        }
                        capturedBtn.classList.add('expanded');
                        capturedDrillTr.hidden = false;
                        // Only fetch once.
                        if (capturedDrillTd.dataset.loaded) return;
                        capturedDrillTd.dataset.loaded = '1';
                        capturedDrillTd.innerHTML = '<span class="aireports-drill-loading">Loading...</span>';
                        var rowKey = { link_id: capturedRow.link_id, label: capturedRow.cells ? capturedRow.cells.label : '' };
                        AiReportsUtil.postForm(drillUrl, {
                            query_plan_json: JSON.stringify(ctx.queryPlan),
                            row_key_json: JSON.stringify(rowKey),
                        }).then(function (data) {
                            if (!data.success) {
                                capturedDrillTd.innerHTML = '<span class="aireports-error">' + escapeHtml(data.message || 'Drilldown failed.') + '</span>';
                                return;
                            }
                            capturedDrillTd.innerHTML = '';
                            capturedDrillTd.appendChild(renderDrillSubTable(data.rows));
                        }).catch(function (err) {
                            capturedDrillTd.innerHTML = '<span class="aireports-error">' + escapeHtml(err.message) + '</span>';
                        });
                    });
                })(row, drillTr, drillTd, btn);

                // Schedule drill row insertion after this tr.
                tr._drillTr = drillTr;
            }
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
            if (tr._drillTr) {
                tbody.appendChild(tr._drillTr);
            }
        });
        t.appendChild(tbody);

        // Totals footer (breakdown / top_n only — block.totals is set server-side).
        if (block.totals) {
            var tfoot = document.createElement('tfoot');
            var ftr = document.createElement('tr');
            ftr.className = 'aireports-table__totals';
            if (canDrill) ftr.appendChild(document.createElement('td'));
            block.columns.forEach(function (col, idx) {
                var td = document.createElement('td');
                if (idx === 0) {
                    td.textContent = 'Total';
                } else if (Object.prototype.hasOwnProperty.call(block.totals, col.key)) {
                    td.textContent = formatValue(block.totals[col.key], col.format);
                }
                ftr.appendChild(td);
            });
            tfoot.appendChild(ftr);
            t.appendChild(tfoot);
        }

        el.appendChild(t);
        return el;
    }

    function renderDrillSubTable(rows) {
        if (!rows || rows.length === 0) {
            var empty = document.createElement('p');
            empty.className = 'aireports-drill-empty';
            empty.textContent = 'No contributing records found.';
            return empty;
        }
        // __links is a per-row map of { columnKey: url } and shouldn't render as its own column.
        var keys = Object.keys(rows[0]).filter(function (k) { return k !== '__links'; });
        var table = document.createElement('table');
        table.className = 'aireports-drill-table';
        var thead = document.createElement('thead');
        thead.innerHTML = '<tr>' + keys.map(function (k) {
            return '<th>' + escapeHtml(k.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); })) + '</th>';
        }).join('') + '</tr>';
        table.appendChild(thead);
        var tbody = document.createElement('tbody');
        rows.forEach(function (row) {
            var tr = document.createElement('tr');
            var links = row.__links || {};
            keys.forEach(function (k) {
                var td = document.createElement('td');
                var v = row[k];
                var text;
                if (k === 'row_total') {
                    text = Number(v).toLocaleString(undefined, { style: 'currency', currency: 'AUD' });
                } else if (k === 'qty_ordered') {
                    text = Number(v).toLocaleString();
                } else {
                    text = v === null || v === undefined ? '-' : String(v);
                }
                if (links[k] && v !== null && v !== undefined) {
                    var a = document.createElement('a');
                    a.href = links[k];
                    a.textContent = text;
                    td.appendChild(a);
                } else {
                    td.textContent = text;
                }
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        return table;
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
            id:        root.dataset.reportId,
            runUrl:    root.dataset.runUrl,
            exportUrl: root.dataset.exportUrl,
            renameUrl: root.dataset.renameUrl,
            deleteUrl: root.dataset.deleteUrl,
            backUrl:   root.dataset.backUrl,
            drillUrl:  root.dataset.drillUrl || null,
            pinUrl:    root.dataset.pinUrl || null,
            unpinUrl:  root.dataset.unpinUrl || null,
            target:    root.querySelector('[data-aireports-result]'),
            queryPlan: null,
        };
        return ctx;
    }

    function readOverride() {
        var root = document.querySelector('[data-period-override]');
        if (!root) return {};
        var from = root.querySelector('[data-period-from]').value;
        var to   = root.querySelector('[data-period-to]').value;
        if (from && to) return { period_from: from, period_to: to };
        return {};
    }

    async function rerun() {
        var c = ensure();
        if (!c) return;
        AiReportsUtil.renderLoading(c.target);
        try {
            var override = readOverride();
            var data = await AiReportsUtil.postForm(c.runUrl, Object.assign({ id: c.id }, override));
            if (!data.success) { AiReportsUtil.renderError(c.target, data.message); return; }
            if (data.query_plan) { c.queryPlan = data.query_plan; }
            AiReportsUtil.renderEnvelope(c.target, data.envelope, {
                saveUrl:   null,
                exportUrl: null,
                queryPlan: c.queryPlan,
            }, c.drillUrl);
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

    function saveScheduleFromTop() {
        // Delegate to the schedule tab's existing Save button. The button is in the DOM
        // even when the tab is not the active visible one (varienTabs only toggles visibility),
        // so its click handler fires and the inline JS in tab_schedule.phtml runs.
        var btn = document.querySelector('[data-schedule-tab-save]');
        if (btn) {
            btn.click();
            return;
        }
        alert('Switch to the Schedule & Email tab and try again.');
    }

    async function pin(reportId) {
        var c = ensure();
        if (!c || !c.pinUrl) return;
        var data = await AiReportsUtil.postForm(c.pinUrl, { id: reportId });
        if (data.success) { window.location.reload(); }
        else { alert('Pin failed: ' + (data.message || 'unknown')); }
    }

    async function unpin(reportId) {
        var c = ensure();
        if (!c || !c.unpinUrl) return;
        var data = await AiReportsUtil.postForm(c.unpinUrl, { id: reportId });
        if (data.success) { window.location.reload(); }
        else { alert('Unpin failed: ' + (data.message || 'unknown')); }
    }

    return {
        rerun: rerun,
        exportCsv: exportCsv,
        rename: rename,
        deleteReport: deleteReport,
        saveScheduleFromTop: saveScheduleFromTop,
        pin: pin,
        unpin: unpin,
    };
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
            document.querySelectorAll('[data-period-apply]').forEach(function (btn) {
                btn.addEventListener('click', function () { aireportsSavedView.rerun(); });
            });
            document.querySelectorAll('[data-period-reset]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var overrideRoot = btn.closest('[data-period-override]');
                    if (overrideRoot) {
                        overrideRoot.querySelector('[data-period-from]').value = '';
                        overrideRoot.querySelector('[data-period-to]').value = '';
                    }
                    aireportsSavedView.rerun();
                });
            });
        }
        // Dashboard: auto-load each pinned report card.
        document.querySelectorAll('[data-aireports-pinned]').forEach(function (pinnedRoot) {
            var runUrl = pinnedRoot.dataset.runUrl;
            pinnedRoot.querySelectorAll('[data-pinned-card]').forEach(async function (card) {
                var id = card.dataset.reportId;
                var target = card.querySelector('[data-aireports-result]');
                try {
                    var data = await AiReportsUtil.postForm(runUrl, { id: id });
                    if (!data.success) {
                        target.innerHTML = '<div class="aireports-error">' + AiReportsUtil.escapeHtml(data.message || 'Failed') + '</div>';
                        return;
                    }
                    target.innerHTML = '';
                    AiReportsUtil.renderEnvelope(target, data.envelope, { saveUrl: null, exportUrl: null });
                } catch (err) {
                    target.innerHTML = '<div class="aireports-error">' + AiReportsUtil.escapeHtml(err.message || 'Failed') + '</div>';
                }
            });
        });
    }

    function setupAsk(root) {
        var form = root.querySelector('[data-aireports-form]');
        var input = root.querySelector('[data-aireports-input]');
        var result = root.querySelector('[data-aireports-result]');
        var generateUrl = root.dataset.generateUrl;
        var saveUrl = root.dataset.saveUrl;
        var exportUrl = root.dataset.exportUrl;
        var drillUrl = root.dataset.drillUrl || null;

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
                }, drillUrl);
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
