(function () {
    'use strict';

    function init() {
        document.querySelectorAll('.aireports-ask').forEach(setupAsk);
        document.querySelectorAll('.aireports-saved').forEach(setupSaved);
    }

    function setupAsk(root) {
        const form = root.querySelector('[data-aireports-form]');
        const input = root.querySelector('[data-aireports-input]');
        const result = root.querySelector('[data-aireports-result]');
        const generateUrl = root.dataset.generateUrl;
        const saveUrl = root.dataset.saveUrl;
        const exportUrl = root.dataset.exportUrl;

        root.querySelectorAll('[data-aireports-chip]').forEach(chip => {
            chip.addEventListener('click', () => {
                input.value = chip.dataset.prompt;
                form.requestSubmit();
            });
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!input.value.trim()) return;
            renderLoading(result);
            try {
                const data = await postForm(generateUrl, { q: input.value });
                if (!data.success) { renderError(result, data.message); return; }
                renderEnvelope(result, data.envelope, {
                    saveUrl,
                    exportUrl,
                    queryPlan: data.query_plan,
                    renderHint: data.render_hint,
                });
            } catch (err) {
                renderError(result, err.message);
            }
        });
    }

    function setupSaved(root) {
        const runUrl    = root.dataset.runUrl;
        const renameUrl = root.dataset.renameUrl;
        const deleteUrl = root.dataset.deleteUrl;
        const exportUrl = root.dataset.exportUrl;

        root.querySelectorAll('[data-aireports-run]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const row = btn.closest('tr');
                const id = row.dataset.reportId;
                const resultRow = root.querySelector(`[data-report-result-row][data-for="${id}"]`);
                const target = resultRow.querySelector('[data-aireports-result]');
                resultRow.hidden = false;
                renderLoading(target);
                try {
                    const data = await postForm(runUrl, { id });
                    if (!data.success) { renderError(target, data.message); return; }
                    renderEnvelope(target, data.envelope, { saveUrl: null, exportUrl: null });
                } catch (err) {
                    renderError(target, err.message);
                }
            });
        });

        root.querySelectorAll('[data-aireports-export]').forEach(btn => {
            btn.addEventListener('click', () => {
                const row = btn.closest('tr');
                const id = row.dataset.reportId;
                submitDownloadForm(exportUrl, { id });
            });
        });

        root.querySelectorAll('[data-aireports-rename]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const row = btn.closest('tr');
                const id = row.dataset.reportId;
                const title = prompt('New title?');
                if (!title) return;
                await postForm(renameUrl, { id, title });
                window.location.reload();
            });
        });

        root.querySelectorAll('[data-aireports-delete]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const row = btn.closest('tr');
                const id = row.dataset.reportId;
                if (!confirm('Delete this report?')) return;
                await postForm(deleteUrl, { id });
                window.location.reload();
            });
        });
    }

    async function postForm(url, payload) {
        const body = new URLSearchParams(payload);
        body.append('form_key', window.FORM_KEY || '');
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body,
            credentials: 'same-origin',
        });
        return res.json();
    }

    /**
     * Triggers a file download by building a temporary form and submitting it.
     * The browser handles the download natively without fetch+blob overhead.
     */
    function submitDownloadForm(url, payload) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = url;
        form.style.display = 'none';

        const addField = (name, value) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        };

        addField('form_key', window.FORM_KEY || '');
        Object.entries(payload).forEach(([k, v]) => addField(k, v));

        document.body.appendChild(form);
        form.submit();
        // Remove after a short delay to keep the DOM clean.
        setTimeout(() => document.body.removeChild(form), 2000);
    }

    function renderLoading(target) {
        target.hidden = false;
        target.innerHTML = '<div class="aireports-loading">Generating report...</div>';
    }

    function renderError(target, msg) {
        target.hidden = false;
        target.innerHTML = `<div class="aireports-error">${escapeHtml(msg || 'Unknown error')}</div>`;
    }

    function renderEnvelope(target, env, ctx) {
        target.hidden = false;
        target.innerHTML = '';
        const head = document.createElement('div');
        head.className = 'aireports-result__head';
        head.innerHTML = `<h4>${escapeHtml(env.title)}</h4><p class="aireports-result__narrative">${escapeHtml(env.narrative)}</p>`;
        target.appendChild(head);

        if (env.meta && env.meta.scope_warning) {
            const banner = document.createElement('div');
            banner.className = 'aireports-result__warning';
            banner.textContent = env.meta.scope_warning;
            target.appendChild(banner);
        }

        env.blocks.forEach(block => target.appendChild(renderBlock(block)));

        const actions = document.createElement('div');
        actions.className = 'aireports-result__actions';

        if (ctx.exportUrl && ctx.queryPlan) {
            const exportBtn = document.createElement('button');
            exportBtn.type = 'button';
            exportBtn.className = 'aireports-result__export';
            exportBtn.textContent = 'Export CSV';
            exportBtn.addEventListener('click', () => {
                submitDownloadForm(ctx.exportUrl, {
                    query_plan_json: JSON.stringify(ctx.queryPlan),
                });
            });
            actions.appendChild(exportBtn);
        }

        if (ctx.saveUrl && ctx.queryPlan) {
            const saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.className = 'aireports-result__save';
            saveBtn.textContent = 'Save report';
            saveBtn.addEventListener('click', async () => {
                const title = prompt('Title for this report?', env.title) || env.title;
                const data = await postForm(ctx.saveUrl, {
                    title,
                    query_plan_json: JSON.stringify(ctx.queryPlan),
                    render_hint_json: JSON.stringify(ctx.renderHint || {}),
                });
                if (data.success) alert('Saved.'); else alert('Failed: ' + data.message);
            });
            actions.appendChild(saveBtn);
        }

        if (actions.hasChildNodes()) {
            target.appendChild(actions);
        }
    }

    function renderBlock(block) {
        const el = document.createElement('div');
        el.className = `aireports-block aireports-block--${block.type}`;
        switch (block.type) {
            case 'kpi':    return renderKpi(el, block);
            case 'chart':  return renderChart(el, block);
            case 'table':  return renderTable(el, block);
        }
        el.textContent = JSON.stringify(block);
        return el;
    }

    function renderKpi(el, block) {
        el.innerHTML = `<div class="aireports-kpi__label">${escapeHtml(block.label)}</div>` +
                       `<div class="aireports-kpi__value">${formatValue(block.value, block.format)}</div>`;
        return el;
    }

    function renderChart(el, block) {
        const canvas = document.createElement('canvas');
        el.appendChild(canvas);
        const isCategorical = block.chart_type === 'pie' || block.chart_type === 'doughnut';
        const cfg = {
            type: block.chart_type,
            data: {
                labels: block.x_axis,
                datasets: block.series.map((s, i) => {
                    if (isCategorical) {
                        return {
                            label: s.name,
                            data: s.data,
                            backgroundColor: s.data.map((_, j) => chartColor(j, 0.75)),
                            borderColor: s.data.map((_, j) => chartColor(j, 1.0)),
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
                                generateLabels(chart) {
                                    const data = chart.data;
                                    if (!data.labels || !data.labels.length || !data.datasets.length) return [];
                                    const ds = data.datasets[0];
                                    return data.labels.map((label, i) => ({
                                        text: String(label),
                                        fillStyle: Array.isArray(ds.backgroundColor) ? ds.backgroundColor[i] : ds.backgroundColor,
                                        strokeStyle: Array.isArray(ds.borderColor) ? ds.borderColor[i] : ds.borderColor,
                                        lineWidth: 1,
                                        index: i,
                                    }));
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
        const t = document.createElement('table');
        t.className = 'aireports-table data';
        const thead = document.createElement('thead');
        thead.innerHTML = '<tr>' + block.columns.map(c => `<th>${escapeHtml(c.label)}</th>`).join('') + '</tr>';
        t.appendChild(thead);
        const tbody = document.createElement('tbody');
        block.rows.forEach(row => {
            const tr = document.createElement('tr');
            block.columns.forEach(col => {
                const td = document.createElement('td');
                const value = row.cells[col.key];
                if (col.key === 'label' && row.link_url) {
                    td.innerHTML = `<a href="${escapeAttr(row.link_url)}">${escapeHtml(String(value))}</a>`;
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
        const palette = ['#4F46E5', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#06B6D4'];
        const c = palette[i % palette.length];
        return c + Math.round(a * 255).toString(16).padStart(2, '0');
    }

    function formatValue(v, fmt) {
        if (v === null || v === undefined) return '-';
        if (fmt === 'integer') return Math.round(Number(v)).toLocaleString();
        if (fmt === 'currency') return Number(v).toLocaleString(undefined, { style: 'currency', currency: 'AUD' });
        if (fmt === 'number') {
            const n = Number(v);
            return Number.isInteger(n) ? n.toLocaleString() : n.toLocaleString(undefined, { maximumFractionDigits: 2 });
        }
        return String(v);
    }

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    }
    function escapeAttr(s) { return escapeHtml(s); }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
