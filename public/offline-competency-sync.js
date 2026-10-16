/*
 * TESDA Connect Phase 3B: narrow offline queue for individual competency statuses.
 * This stores only IDs and a status transition in IndexedDB. It never caches page
 * responses or documents. Use only on a trusted device; IndexedDB is not encrypted.
 */
(() => {
    const script = document.currentScript;
    const syncUrl = script?.dataset?.syncUrl;
    const userId = script?.dataset?.userId;
    if (!syncUrl || !userId) return;

    const DB_NAME = 'tesda-connect-offline-queue';
    const STORE_NAME = 'competency-mutations';
    let syncing = false;
    let databasePromise;

    function openDb() {
        if (databasePromise) return databasePromise;
        databasePromise = new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, 1);
            request.onupgradeneeded = () => {
                const db = request.result;
                if (!db.objectStoreNames.contains(STORE_NAME)) {
                    const store = db.createObjectStore(STORE_NAME, { keyPath: 'mutationId' });
                    store.createIndex('byUserId', 'userId', { unique: false });
                    store.createIndex('byUserTask', ['userId', 'enrollmentId', 'taskId'], { unique: false });
                }
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error || new Error('Could not open offline queue.'));
        });
        return databasePromise;
    }

    async function requestResult(mode, action) {
        const db = await openDb();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction(STORE_NAME, mode);
            const store = transaction.objectStore(STORE_NAME);
            const request = action(store);
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error || new Error('Offline queue request failed.'));
        });
    }

    const getAll = () => requestResult('readonly', (store) => store.getAll());
    const put = (entry) => requestResult('readwrite', (store) => store.put(entry));
    const remove = (mutationId) => requestResult('readwrite', (store) => store.delete(mutationId));

    function ensurePanel() {
        let panel = document.getElementById('tesda-competency-sync-status');
        if (panel) return panel;
        panel = document.createElement('div');
        panel.id = 'tesda-competency-sync-status';
        panel.setAttribute('role', 'status');
        panel.setAttribute('aria-live', 'polite');
        panel.style.cssText = 'position:fixed;z-index:99998;left:16px;bottom:16px;max-width:min(520px,calc(100vw - 32px));padding:12px 14px;border-radius:12px;background:#0f172a;color:#fff;font:600 13px/1.5 system-ui,sans-serif;box-shadow:0 8px 28px rgb(15 23 42 / 18%);display:none;';
        document.body.appendChild(panel);
        return panel;
    }

    function showMessage(message, type = 'info') {
        const panel = ensurePanel();
        panel.replaceChildren();
        const text = document.createElement('span');
        text.textContent = message;
        panel.appendChild(text);
        panel.style.background = type === 'error' ? '#7f1d1d' : (type === 'success' ? '#065f46' : '#0f172a');
        panel.style.display = 'block';
    }

    async function renderQueueSummary() {
        try {
            const entries = (await getAll()).filter((entry) => String(entry.userId) === String(userId));
            const pending = entries.filter((entry) => entry.syncState === 'pending').length;
            const conflicts = entries.filter((entry) => ['conflict', 'rejected'].includes(entry.syncState));
            const panel = ensurePanel();

            if (pending === 0 && conflicts.length === 0) {
                panel.style.display = 'none';
                return;
            }

            panel.replaceChildren();
            const message = document.createElement('div');
            if (pending > 0) {
                message.textContent = navigator.onLine
                    ? `${pending} competency change(s) waiting to synchronize.`
                    : `${pending} competency change(s) saved on this device and waiting for internet.`;
            } else {
                message.textContent = `${conflicts.length} offline change(s) need review. Check the current competency statuses online before clearing these items.`;
            }
            panel.appendChild(message);
            if (conflicts.length > 0) {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = 'Clear reviewed conflicts';
                button.style.cssText = 'margin-top:8px;border:1px solid #cbd5e1;border-radius:7px;padding:5px 8px;background:#fff;color:#0f172a;font-weight:700;cursor:pointer;';
                button.addEventListener('click', async () => {
                    const fresh = (await getAll()).filter((entry) => String(entry.userId) === String(userId) && ['conflict', 'rejected'].includes(entry.syncState));
                    await Promise.all(fresh.map((entry) => remove(entry.mutationId)));
                    await renderQueueSummary();
                });
                panel.appendChild(button);
            }
            panel.style.background = conflicts.length ? '#7c2d12' : '#0f172a';
            panel.style.display = 'block';
        } catch (error) {
            showMessage('Offline queue is unavailable in this browser. No change was queued.', 'error');
            console.warn('TESDA offline queue error.', error);
        }
    }

    function createMutationId() {
        if (window.crypto?.randomUUID) return window.crypto.randomUUID();
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0'));
        return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`;
    }

    async function enqueue(select) {
        const enrollmentId = Number(select.dataset.enrollmentId);
        const taskId = Number(select.dataset.taskId);
        const status = select.value;
        const validStatuses = ['submitted_output_correct', 'competent', 'not_yet_competent', 'not_assessed'];
        if (!enrollmentId || !taskId || !validStatuses.includes(status)) {
            throw new Error('This competency row is missing valid identifiers or status. Reload the page when online.');
        }

        const all = await getAll();
        const existing = all.find((entry) => String(entry.userId) === String(userId)
            && Number(entry.enrollmentId) === enrollmentId
            && Number(entry.taskId) === taskId);

        if (existing && ['conflict', 'rejected'].includes(existing.syncState)) {
            throw new Error('This task has an unresolved sync conflict. Reconnect, review the server status, then clear conflicts before making another offline change.');
        }

        const entry = existing || {
            mutationId: createMutationId(),
            userId: String(userId),
            enrollmentId,
            taskId,
            baseStatus: select.dataset.baseStatus || 'not_assessed',
            baseUpdatedAt: select.dataset.baseUpdatedAt || null,
            createdAt: new Date().toISOString(),
            syncState: 'pending',
            attempts: 0,
        };
        entry.status = status;
        entry.syncState = 'pending';
        entry.lastError = null;
        entry.updatedLocallyAt = new Date().toISOString();
        await put(entry);
        await renderQueueSummary();
    }

    function updateVisibleRevision(result) {
        const select = document.querySelector(`[data-offline-competency-status][data-enrollment-id="${Number(result.enrollment_id || 0)}"][data-task-id="${Number(result.task_id || 0)}"]`);
        if (!select) return;
        if (result.server_status) select.dataset.baseStatus = result.server_status;
        select.dataset.baseUpdatedAt = result.server_updated_at || '';
    }

    async function synchronize() {
        if (syncing || !navigator.onLine || !window.fetch) return;
        syncing = true;
        try {
            let entries = (await getAll()).filter((entry) => String(entry.userId) === String(userId) && entry.syncState === 'pending');
            while (entries.length > 0 && navigator.onLine) {
                const batch = entries.slice(0, 25);
                let response;
                try {
                    response = await fetch(syncUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        },
                        body: JSON.stringify({mutations: batch.map((entry) => ({
                            mutation_id: entry.mutationId,
                            enrollment_id: entry.enrollmentId,
                            task_id: entry.taskId,
                            status: entry.status,
                            base_status: entry.baseStatus,
                            base_updated_at: entry.baseUpdatedAt || null,
                        }))}),
                    });
                } catch {
                    await renderQueueSummary();
                    return;
                }

                if (!response.ok) {
                    if (response.status === 401 || response.status === 403 || response.status === 419) {
                        showMessage('Offline changes are still on this device. Sign in again with the same authorized account and reconnect to synchronize.', 'error');
                    }
                    return;
                }

                const body = await response.json();
                const resultMap = new Map((body.results || []).map((result) => [result.mutation_id, result]));
                for (const entry of batch) {
                    const result = resultMap.get(entry.mutationId);
                    if (!result) continue;
                    if (['synced', 'already_applied'].includes(result.status)) {
                        await remove(entry.mutationId);
                        updateVisibleRevision({ ...result, enrollment_id: entry.enrollmentId, task_id: entry.taskId });
                    } else {
                        entry.syncState = result.status === 'conflict' ? 'conflict' : 'rejected';
                        entry.lastError = result.message || 'The server did not accept this offline change.';
                        entry.serverStatus = result.server_status || null;
                        entry.serverUpdatedAt = result.server_updated_at || null;
                        await put(entry);
                    }
                }
                entries = (await getAll()).filter((entry) => String(entry.userId) === String(userId) && entry.syncState === 'pending');
            }
        } catch (error) {
            console.warn('TESDA competency synchronization failed.', error);
        } finally {
            syncing = false;
            await renderQueueSummary();
        }
    }

    // Capture first so Livewire's change listener is not called when offline.
    document.addEventListener('change', (event) => {
        const select = event.target?.closest?.('select[data-offline-competency-status]');
        if (!select || navigator.onLine || select.disabled) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        enqueue(select).then(() => {
            showMessage('Offline competency change saved on this device. It will sync when internet returns.');
        }).catch((error) => showMessage(error.message || 'This change could not be queued.', 'error'));
    }, true);

    window.addEventListener('online', synchronize);
    window.addEventListener('offline', renderQueueSummary);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') synchronize();
    });
    window.addEventListener('load', () => {
        renderQueueSummary();
        synchronize();
    }, { once: true });
    renderQueueSummary();
    if (navigator.onLine) synchronize();
})();
