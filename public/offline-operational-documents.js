(() => {
    if (window.__tesdaOperationalDocumentQueueInstalled) return;
    window.__tesdaOperationalDocumentQueueInstalled = true;

    const DB_NAME = 'tesda-connect-v2-operational-documents';
    const STORE = 'pending-uploads';
    const MAX_BYTES = 10 * 1024 * 1024;

    function openDatabase() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, 1);
            request.onupgradeneeded = () => {
                const db = request.result;
                if (!db.objectStoreNames.contains(STORE)) db.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    async function withStore(mode, action) {
        const db = await openDatabase();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, mode);
            const store = tx.objectStore(STORE);
            let result;
            try { result = action(store); } catch (error) { reject(error); db.close(); return; }
            tx.oncomplete = () => { db.close(); resolve(result?.result ?? result); };
            tx.onerror = () => { db.close(); reject(tx.error); };
            tx.onabort = () => { db.close(); reject(tx.error); };
        });
    }

    async function allQueued() {
        const db = await openDatabase();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, 'readonly');
            const request = tx.objectStore(STORE).getAll();
            request.onsuccess = () => resolve(request.result || []);
            request.onerror = () => reject(request.error);
            tx.oncomplete = () => db.close();
        });
    }

    function formValue(form, id) {
        return document.getElementById(id)?.value ?? '';
    }

    function statusText(text, error = false) {
        const el = document.getElementById('offline-upload-status');
        if (!el) return;
        el.textContent = text;
        el.classList.toggle('text-rose-600', error);
        el.classList.toggle('text-slate-500', !error);
        el.classList.toggle('text-emerald-700', !error && text.toLowerCase().includes('synced'));
    }

    async function refreshQueueStatus() {
        try {
            const queue = await allQueued();
            const count = queue.length;
            if (count && !navigator.onLine) statusText(`${count} document(s) queued on this device. They will upload after reconnection while signed in as the same account.`);
            else if (count) statusText(`${count} document(s) waiting to sync. Keep this page open while online.`);
        } catch (_) {
            // If IndexedDB is disabled, normal online uploads still work.
        }
    }

    async function queueForm(form) {
        if (form.dataset.wasQueued === 'pending' || form.dataset.wasQueued === 'true') return;
        const input = form.querySelector('input[type="file"]');
        const file = input?.files?.[0];
        if (!file) { statusText('Choose a file before queueing an offline upload.', true); return; }
        form.dataset.wasQueued = 'pending';
        if (file.size > MAX_BYTES) { statusText('The file exceeds the 10 MB limit.', true); return; }
        const userId = form.dataset.userId || document.querySelector('[data-auth-user-id]')?.dataset.authUserId || '';
        if (!userId) { delete form.dataset.wasQueued; statusText('Could not confirm the signed-in account. Reload the page and try again.', true); return; }
        const record = {
            userId: String(userId),
            url: form.dataset.offlineUploadUrl,
            institution_id: formValue(form, 'doc-institution'),
            category: formValue(form, 'doc-category'),
            title: formValue(form, 'doc-title'),
            notes: formValue(form, 'doc-notes'),
            course_id: formValue(form, 'doc-course'),
            training_batch_id: formValue(form, 'doc-batch'),
            enrollment_application_id: formValue(form, 'doc-enrollment'),
            fileName: file.name,
            fileType: file.type || 'application/octet-stream',
            blob: file,
            queuedAt: new Date().toISOString(),
        };
        if (!record.institution_id || !record.title.trim()) { delete form.dataset.wasQueued; statusText('Please select an institution and enter a document title.', true); return; }
        try {
            await withStore('readwrite', store => store.add(record));
            input.value = '';
            form.dataset.wasQueued = 'true';
            statusText('Document saved in this browser’s offline queue. It will sync when you reconnect and sign in with this same account.');
            await refreshQueueStatus();
        } catch (_) {
            delete form.dataset.wasQueued;
            statusText('Could not store this file offline. Keep a local copy and upload it when connected.', true);
        }
    }

    async function syncQueue() {
        if (!navigator.onLine) return;
        const form = document.getElementById('operational-document-form');
        if (!form) return;
        const currentUserId = String(form.dataset.userId || '');
        if (!currentUserId) return;
        let queue;
        try { queue = await allQueued(); } catch (_) { return; }
        let synced = 0;
        for (const record of queue) {
            if (String(record.userId) !== currentUserId) continue;
            const body = new FormData();
            body.append('institution_id', record.institution_id);
            body.append('category', record.category);
            body.append('title', record.title);
            body.append('notes', record.notes || '');
            body.append('course_id', record.course_id || '');
            body.append('training_batch_id', record.training_batch_id || '');
            body.append('enrollment_application_id', record.enrollment_application_id || '');
            body.append('upload', record.blob, record.fileName);
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            try {
                const response = await fetch(record.url, {
                    method: 'POST', body, credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) },
                });
                if (response.ok) {
                    await withStore('readwrite', store => store.delete(record.id));
                    synced++;
                } else if (response.status >= 400 && response.status < 500 && response.status !== 419 && response.status !== 429) {
                    statusText(`A queued upload was rejected by the server (HTTP ${response.status}). It remains on this device; verify the institution, file type, and login.`, true);
                    break;
                } else {
                    break;
                }
            } catch (_) {
                break;
            }
        }
        await refreshQueueStatus();
        if (synced) {
            statusText(`${synced} offline document(s) synced successfully. Refresh the document list to see them.`);
            try { window.Livewire?.dispatch('operational-documents-synced'); } catch (_) {}
        }
    }

    document.addEventListener('change', event => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || input.type !== 'file') return;
        const form = input.form;
        if (!form || form.id !== 'operational-document-form' || navigator.onLine) return;
        // Intercept before Livewire starts its own temporary upload request while offline.
        event.preventDefault();
        event.stopImmediatePropagation();
        queueForm(form);
    }, true);

    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.id !== 'operational-document-form') return;
        if (navigator.onLine) return; // Preserve normal Livewire uploads while online.
        event.preventDefault();
        event.stopImmediatePropagation();
        if (form.dataset.wasQueued === 'true') {
            statusText('Document is already queued on this device and will sync when you reconnect.');
            return;
        }
        queueForm(form);
    }, true);

    window.addEventListener('online', syncQueue);
    document.addEventListener('livewire:navigated', () => { refreshQueueStatus(); if (navigator.onLine) syncQueue(); });
    document.addEventListener('DOMContentLoaded', () => { refreshQueueStatus(); if (navigator.onLine) syncQueue(); });
    refreshQueueStatus();
})();
