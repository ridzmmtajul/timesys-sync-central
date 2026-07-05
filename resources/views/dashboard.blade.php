<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Timesys Sync Central</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
            background: #0a1228;
            color: #eef6ff;
            padding: 28px;
        }
        h1 {
            font-size: 20px;
            letter-spacing: -0.5px;
            margin: 0 0 4px;
        }
        .subtitle {
            color: #9bb0da;
            font-size: 13px;
            margin: 0 0 20px;
        }
        .kpi-strip {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .kpi-card {
            min-width: 140px;
            padding: 12px 16px;
            border-radius: 12px;
            background: rgba(24, 35, 70, 0.82);
            border: 1px solid rgba(126, 153, 210, 0.16);
        }
        .kpi-label {
            display: block;
            font-size: 11px;
            color: #99b2df;
            margin-bottom: 4px;
        }
        .kpi-card strong { font-size: 18px; }
        .surface {
            border-radius: 18px;
            background: rgba(10, 18, 40, 0.58);
            border: 1px solid rgba(121, 146, 207, 0.16);
            padding: 14px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        thead { background: rgba(16, 24, 50, 0.98); }
        th, td {
            padding: 12px 14px;
            border-bottom: 1px solid rgba(121, 146, 207, 0.12);
            text-align: left;
        }
        th {
            color: #9bb0da;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        td.muted { color: #9bb0da; }
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            text-transform: capitalize;
        }
        .status-success { background: rgba(31, 191, 184, 0.18); color: #75e7d7; }
        .status-partial { background: rgba(240, 180, 41, 0.18); color: #f0c040; }
        .status-failed  { background: rgba(220, 60, 60, 0.16); color: #f08080; }
        .empty, .loading {
            text-align: center;
            padding: 50px 20px;
            color: #9bb0da;
        }
        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            margin-top: 14px;
            font-size: 13px;
            color: #9bb0da;
        }
        .pagination button {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: 1px solid rgba(121, 146, 207, 0.2);
            background: rgba(17, 27, 56, 0.8);
            color: #9bb0da;
            cursor: pointer;
        }
        .pagination button:disabled { opacity: 0.35; cursor: default; }
        .header-bar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .btn-push {
            border: 0;
            padding: 11px 18px;
            border-radius: 999px;
            background: linear-gradient(90deg, #1fbfb8 0%, #52d3d0 100%);
            color: #06162f;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            white-space: nowrap;
        }
        .btn-push:disabled { opacity: 0.6; cursor: default; }
        .push-result {
            margin: 0 0 16px;
            font-size: 13px;
            color: #9bb0da;
        }
        .progress-wrap {
            margin: 0 0 16px;
        }
        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #9bb0da;
            margin-bottom: 6px;
        }
        .progress-track {
            height: 8px;
            border-radius: 999px;
            background: rgba(24, 35, 70, 0.82);
            border: 1px solid rgba(126, 153, 210, 0.16);
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            width: 0%;
            border-radius: 999px;
            background: linear-gradient(90deg, #1fbfb8 0%, #52d3d0 100%);
            transition: width 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        /* Moving stripe overlay so the bar still reads as "working" during a
           long batch, when the width itself may not move for many seconds. */
        .progress-fill.is-active::after {
            content: '';
            position: absolute;
            inset: 0;
            background-image: repeating-linear-gradient(
                45deg,
                rgba(255, 255, 255, 0.35) 0,
                rgba(255, 255, 255, 0.35) 8px,
                transparent 8px,
                transparent 16px
            );
            background-size: 32px 32px;
            animation: progress-stripes 0.9s linear infinite;
        }
        @keyframes progress-stripes {
            from { background-position: 0 0; }
            to   { background-position: 32px 0; }
        }
    </style>
</head>
<body>
    <div class="header-bar">
        <div>
            <h1>Timesys Sync Central</h1>
            <p class="subtitle">Pushes local records to timesys-v2 and shows sync activity. No login required.</p>
        </div>
        <button class="btn-push" id="push-all-btn" onclick="pushAll()">Push All</button>
    </div>

    <div class="kpi-strip" id="kpi-strip"></div>

    <div class="progress-wrap" id="push-progress" style="display:none">
        <div class="progress-label">
            <span id="progress-text">Pushing...</span>
            <span id="progress-count"></span>
        </div>
        <div class="progress-track">
            <div class="progress-fill" id="progress-fill"></div>
        </div>
    </div>
    <p class="push-result" id="push-result"></p>

    <div class="surface">
        <div id="table-wrap"><div class="loading">Loading...</div></div>
        <div class="pagination" id="pagination" style="display:none"></div>
    </div>

    <script>
        const KPI_LABELS = {
            employees: 'Employees',
            offices: 'Offices',
            office_divisions: 'Office Divisions',
            work_schedules: 'Work Schedules',
            attendances: 'Attendances',
        };

        const PUSH_MODULES = [
            { key: 'offices', label: 'Offices', endpoint: 'push-offices' },
            { key: 'office_divisions', label: 'Office Divisions', endpoint: 'push-office-divisions' },
            { key: 'employees', label: 'Employees', endpoint: 'push-employees' },
            { key: 'work_schedules', label: 'Work Schedules', endpoint: 'push-work-schedules' },
            { key: 'attendances', label: 'Attendances', endpoint: 'push-attendances' },
        ];

        let currentPage = 1;

        function formatDate(value) {
            if (!value) return '—';
            return new Date(value).toLocaleString();
        }

        async function loadCounts() {
            const res = await fetch('/api/sync/pending-counts');
            const data = await res.json();
            const strip = document.getElementById('kpi-strip');
            strip.innerHTML = Object.entries(KPI_LABELS).map(([key, label]) => `
                <div class="kpi-card">
                    <span class="kpi-label">${label} Pending</span>
                    <strong>${Number(data[key] ?? 0).toLocaleString()}</strong>
                </div>
            `).join('');
        }

        async function loadLogs(page = 1) {
            currentPage = page;
            const wrap = document.getElementById('table-wrap');
            wrap.innerHTML = '<div class="loading">Loading...</div>';

            const res = await fetch(`/api/sync/logs?page=${page}`);
            const data = await res.json();
            const logs = data.data || [];

            if (!logs.length) {
                wrap.innerHTML = '<div class="empty">No sync activity yet. Waiting for local instances to push records.</div>';
                document.getElementById('pagination').style.display = 'none';
                return;
            }

            wrap.innerHTML = `
                <table>
                    <thead>
                        <tr>
                            <th>Module</th>
                            <th>Status</th>
                            <th>Synced</th>
                            <th>Existing</th>
                            <th>Skipped</th>
                            <th>Message</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${logs.map(log => `
                            <tr>
                                <td>${log.module}</td>
                                <td><span class="status-badge status-${log.status}">${log.status}</span></td>
                                <td>${log.synced_count}</td>
                                <td>${log.existing_count}</td>
                                <td>${log.skipped_count}</td>
                                <td class="muted">${log.message ?? '—'}</td>
                                <td class="muted">${formatDate(log.created_at)}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;

            const pagination = document.getElementById('pagination');
            if (data.last_page > 1) {
                pagination.style.display = 'flex';
                pagination.innerHTML = `
                    <button ${data.current_page === 1 ? 'disabled' : ''} onclick="loadLogs(${data.current_page - 1})">&larr;</button>
                    <span>Page ${data.current_page} of ${data.last_page} (${data.total} total)</span>
                    <button ${data.current_page === data.last_page ? 'disabled' : ''} onclick="loadLogs(${data.current_page + 1})">&rarr;</button>
                `;
            } else {
                pagination.style.display = 'none';
            }
        }

        // A single push call only handles as many chunks as fit under the
        // server's execution time budget (see stopped_early/stop_reason in
        // the response). Large backlogs — attendances especially — need
        // several calls back to back, so keep calling the same endpoint
        // until it reports nothing left to do.
        async function pushModuleUntilDone(mod, progressText) {
            let totalSynced = 0, totalExisting = 0, totalSkipped = 0;
            const messages = [];
            let iteration = 0;
            const maxIterations = 200; // safety cap against a runaway retry loop

            while (true) {
                iteration++;
                progressText.textContent = iteration === 1
                    ? `Pushing ${mod.label}...`
                    : `Pushing ${mod.label} (batch ${iteration})...`;

                let data;
                try {
                    const res = await fetch(`/api/sync/${mod.endpoint}`, { method: 'POST' });
                    data = await res.json();
                } catch (e) {
                    messages.push(`${mod.label}: request failed.`);
                    break;
                }

                totalSynced += data.synced ?? 0;
                totalExisting += data.existing ?? 0;
                totalSkipped += data.skipped ?? 0;

                const madeProgress = (data.synced ?? 0) + (data.existing ?? 0) + (data.skipped ?? 0) > 0;

                if (!data.stopped_early) {
                    if (data.message) messages.push(`${mod.label}: ${data.message}`);
                    break;
                }

                // A real failure (unreachable/erroring target) with no progress
                // this round won't be fixed by immediately retrying — stop and
                // let the next manual push try again.
                if (data.stop_reason === 'error' && !madeProgress) {
                    if (data.message) messages.push(`${mod.label}: ${data.message}`);
                    break;
                }

                if (iteration >= maxIterations) {
                    messages.push(`${mod.label}: stopped after ${maxIterations} batches; some records may still be pending.`);
                    break;
                }
            }

            return { totalSynced, totalExisting, totalSkipped, messages };
        }

        async function pushAll() {
            const btn = document.getElementById('push-all-btn');
            const result = document.getElementById('push-result');
            const progressWrap = document.getElementById('push-progress');
            const progressFill = document.getElementById('progress-fill');
            const progressText = document.getElementById('progress-text');
            const progressCount = document.getElementById('progress-count');

            btn.disabled = true;
            result.textContent = '';
            progressWrap.style.display = 'block';
            progressFill.style.width = '0%';
            progressFill.classList.add('is-active');

            const total = PUSH_MODULES.length;
            let totalSynced = 0, totalExisting = 0, totalSkipped = 0;
            const messages = [];

            for (let i = 0; i < total; i++) {
                const mod = PUSH_MODULES[i];
                progressCount.textContent = `${i}/${total}`;
                progressFill.style.width = `${Math.round((i / total) * 100)}%`;

                const modResult = await pushModuleUntilDone(mod, progressText);
                totalSynced += modResult.totalSynced;
                totalExisting += modResult.totalExisting;
                totalSkipped += modResult.totalSkipped;
                messages.push(...modResult.messages);

                progressFill.style.width = `${Math.round(((i + 1) / total) * 100)}%`;
                progressCount.textContent = `${i + 1}/${total}`;
            }

            progressText.textContent = 'Done';
            result.textContent = `${totalSynced} synced, ${totalExisting} existing, ${totalSkipped} skipped. ${messages.join(' ')}`;

            progressFill.classList.remove('is-active');
            btn.disabled = false;
            setTimeout(() => { progressWrap.style.display = 'none'; }, 600);

            await Promise.all([loadCounts(), loadLogs(currentPage)]);
        }

        loadCounts();
        loadLogs();
    </script>
</body>
</html>
