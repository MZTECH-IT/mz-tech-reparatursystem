/**
 * MZ Tech Repair System – Main JavaScript
 * Vanilla JS, no external dependencies.
 */

'use strict';

// ─────────────────────────────────────────────────────────────────────────────
// Namespace
// ─────────────────────────────────────────────────────────────────────────────
const mztech = (() => {

    // ── 1. CSRF token helper ─────────────────────────────────────────────────
    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    // ── Spinner helpers ──────────────────────────────────────────────────────
    function setButtonLoading(btn, loading) {
        if (!btn) return;
        if (loading) {
            btn.dataset.originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner" aria-hidden="true"></span> Bitte warten…';
        } else {
            btn.disabled = false;
            btn.innerHTML = btn.dataset.originalText || btn.innerHTML;
        }
    }

    // ── 1. AJAX helper ───────────────────────────────────────────────────────
    /**
     * mztech.post(url, data, options)
     * @param {string}  url
     * @param {Object|FormData} data  Plain object or FormData
     * @param {Object}  [options]
     * @param {HTMLElement} [options.button]  Submit button to show spinner on
     * @returns {Promise<any>}  Resolves with parsed JSON or rejects with Error
     */
    function post(url, data, options = {}) {
        const { button } = options;
        setButtonLoading(button, true);

        let body;
        let headers = {
            'X-CSRF-Token': getCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        };

        if (data instanceof FormData) {
            body = data;
            // Do NOT set Content-Type — browser sets multipart boundary automatically
        } else {
            body = JSON.stringify(data);
            headers['Content-Type'] = 'application/json';
        }

        return fetch(url, { method: 'POST', headers, body, credentials: 'same-origin' })
            .then(res => {
                setButtonLoading(button, false);
                if (!res.ok) {
                    return res.json().catch(() => ({})).then(err => {
                        throw new Error(err.message || `HTTP ${res.status}`);
                    });
                }
                return res.json();
            })
            .catch(err => {
                setButtonLoading(button, false);
                throw err;
            });
    }

    // ── 9. Money formatting ──────────────────────────────────────────────────
    /**
     * mztech.formatMoney(amount) → "1.234,56 €"
     * @param {number|string} amount
     * @returns {string}
     */
    function formatMoney(amount) {
        const n = parseFloat(amount);
        if (isNaN(n)) return '0,00 €';
        return n.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    }

    return { post, formatMoney, getCsrfToken };
})();

// ─────────────────────────────────────────────────────────────────────────────
// DOM-ready initialiser
// ─────────────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {

    // ── 2. Flash message auto-hide ───────────────────────────────────────────
    document.querySelectorAll('.alert[data-auto-hide], .alert.alert-success, .alert.alert-info')
        .forEach(el => {
            // Skip persistent alerts (data-persist)
            if (el.hasAttribute('data-persist')) return;

            const delay = parseInt(el.dataset.delay || '5000', 10);
            setTimeout(() => {
                el.style.transition = 'opacity .5s ease, max-height .5s ease, margin .5s ease, padding .5s ease';
                el.style.opacity    = '0';
                el.style.maxHeight  = '0';
                el.style.overflow   = 'hidden';
                el.style.margin     = '0';
                el.style.padding    = '0';
                el.addEventListener('transitionend', () => el.remove(), { once: true });
            }, delay);
        });

    // ── 3. Delete / action confirmation ─────────────────────────────────────
    document.addEventListener('click', e => {
        const target = e.target.closest('[data-confirm]');
        if (!target) return;
        const message = target.dataset.confirm || 'Sind Sie sicher?';
        if (!confirm(message)) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    });

    // ── 4. Table live search ─────────────────────────────────────────────────
    document.querySelectorAll('[data-search-table]').forEach(input => {
        const tableId = input.dataset.searchTable;
        const table   = document.getElementById(tableId);
        if (!table) return;

        const rows = () => Array.from(table.querySelectorAll('tbody tr'));

        input.addEventListener('input', () => {
            const query = input.value.trim().toLowerCase();
            rows().forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = (!query || text.includes(query)) ? '' : 'none';
            });

            // Show/hide "no results" row if present
            const noResults = table.querySelector('[data-no-results]');
            if (noResults) {
                const visible = rows().filter(r => r.style.display !== 'none' && !r.hasAttribute('data-no-results'));
                noResults.style.display = visible.length === 0 ? '' : 'none';
            }
        });
    });

    // ── 5. Modal management ──────────────────────────────────────────────────

    /**
     * openModal(id)  – show a modal by its element id
     */
    window.openModal = function openModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.hidden       = false;
        modal.style.display = '';
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        // Focus first focusable element
        const focusable = modal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        if (focusable) focusable.focus();
    };

    /**
     * closeModal(id) – hide a modal by its element id
     */
    window.closeModal = function closeModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    };

    // Close on Escape
    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('[role="dialog"]:not([hidden])').forEach(m => {
            closeModal(m.id);
        });
    });

    // Close on overlay click
    document.addEventListener('click', e => {
        if (!e.target.matches('[data-modal-overlay]')) return;
        const modal = e.target.closest('[role="dialog"]');
        if (modal) closeModal(modal.id);
    });

    // Wire up [data-modal-open] and [data-modal-close] buttons declaratively
    document.querySelectorAll('[data-modal-open]').forEach(btn => {
        btn.addEventListener('click', () => openModal(btn.dataset.modalOpen));
    });
    document.querySelectorAll('[data-modal-close]').forEach(btn => {
        btn.addEventListener('click', () => closeModal(btn.dataset.modalClose || btn.closest('[role="dialog"]')?.id));
    });

    // ── 6. Form validation helpers ───────────────────────────────────────────
    document.querySelectorAll('form[data-validate]').forEach(form => {
        form.addEventListener('submit', e => {
            let valid = true;

            form.querySelectorAll('[required]').forEach(field => {
                const isEmpty = field.value.trim() === '';
                field.classList.toggle('field-error', isEmpty);

                if (isEmpty) {
                    valid = false;
                    // Show inline message if sibling .field-error-msg exists
                    const msg = field.parentElement.querySelector('.field-error-msg');
                    if (msg) msg.hidden = false;
                } else {
                    const msg = field.parentElement.querySelector('.field-error-msg');
                    if (msg) msg.hidden = true;
                }
            });

            // Email fields
            form.querySelectorAll('input[type="email"]').forEach(field => {
                if (field.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(field.value)) {
                    field.classList.add('field-error');
                    valid = false;
                }
            });

            if (!valid) {
                e.preventDefault();
                // Scroll to first error
                const first = form.querySelector('.field-error');
                if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });

        // Clear error on input
        form.querySelectorAll('[required], input[type="email"]').forEach(field => {
            field.addEventListener('input', () => {
                field.classList.remove('field-error');
                const msg = field.parentElement.querySelector('.field-error-msg');
                if (msg) msg.hidden = true;
            });
        });
    });

    // ── 7. Photo upload (AJAX, with preview) ────────────────────────────────
    const photoForm = document.getElementById('repair-photo-form');
    if (photoForm) {
        const fileInput   = photoForm.querySelector('input[type="file"]');
        const previewEl   = document.getElementById('photo-preview');
        const submitBtn   = photoForm.querySelector('[type="submit"]');
        const resultEl    = document.getElementById('photo-upload-result');

        // Live preview before upload
        if (fileInput && previewEl) {
            fileInput.addEventListener('change', () => {
                const file = fileInput.files[0];
                if (!file) return;
                if (!file.type.startsWith('image/')) {
                    showUploadResult('error', 'Nur Bilddateien erlaubt.');
                    return;
                }
                const reader = new FileReader();
                reader.onload = ev => {
                    previewEl.src    = ev.target.result;
                    previewEl.hidden = false;
                };
                reader.readAsDataURL(file);
            });
        }

        // AJAX submit
        photoForm.addEventListener('submit', e => {
            e.preventDefault();
            if (!fileInput || !fileInput.files.length) {
                showUploadResult('error', 'Bitte wählen Sie eine Datei aus.');
                return;
            }

            const fd = new FormData(photoForm);

            mztech.post((window.APP_URL_BASE || '') + '/api/repairs.php?action=upload_photo', fd, { button: submitBtn })
                .then(data => {
                    if (data.success) {
                        showUploadResult('success', data.message || 'Foto hochgeladen.');
                        if (previewEl) {
                            previewEl.src    = data.url || previewEl.src;
                            previewEl.hidden = false;
                        }
                        // Refresh photo gallery if present
                        const gallery = document.getElementById('photo-gallery');
                        if (gallery && data.html) gallery.insertAdjacentHTML('beforeend', data.html);
                        photoForm.reset();
                    } else {
                        showUploadResult('error', data.message || 'Upload fehlgeschlagen.');
                    }
                })
                .catch(err => showUploadResult('error', err.message));
        });

        function showUploadResult(type, message) {
            if (!resultEl) return;
            resultEl.className  = `alert alert-${type === 'error' ? 'danger' : 'success'}`;
            resultEl.textContent = message;
            resultEl.hidden     = false;
            setTimeout(() => { resultEl.hidden = true; }, 5000);
        }
    }

    // ── 8. Status change (AJAX, repairs_view.php) ────────────────────────────
    const statusForm = document.getElementById('repair-status-form');
    if (statusForm) {
        statusForm.addEventListener('submit', e => {
            e.preventDefault();
            const btn  = statusForm.querySelector('[type="submit"]');
            const data = Object.fromEntries(new FormData(statusForm));

            mztech.post((window.APP_URL_BASE || '') + '/api/repairs.php?action=update_status', data, { button: btn })
                .then(res => {
                    if (res.success) {
                        // Update status badge in the DOM if present
                        const badge = document.getElementById('repair-status-badge');
                        if (badge) {
                            badge.textContent  = res.status_label || data.status;
                            badge.className    = `status-badge status-${data.status}`;
                        }
                        showStatusMessage('success', res.message || 'Status aktualisiert.');
                    } else {
                        showStatusMessage('error', res.message || 'Fehler beim Aktualisieren.');
                    }
                })
                .catch(err => showStatusMessage('error', err.message));
        });

        function showStatusMessage(type, message) {
            let el = document.getElementById('status-form-result');
            if (!el) {
                el = document.createElement('div');
                el.id = 'status-form-result';
                statusForm.after(el);
            }
            el.className  = `alert alert-${type === 'error' ? 'danger' : 'success'}`;
            el.textContent = message;
            el.hidden     = false;
            setTimeout(() => { el.hidden = true; }, 4000);
        }
    }

    // ── 10. Dynamic form show/hide (data-depends-on) ─────────────────────────
    /**
     * Usage:
     *   <div data-depends-on="repair_type" data-depends-value="wasserschaden">...</div>
     *
     * The element is shown only when the field whose name matches data-depends-on
     * has a value equal to data-depends-value.
     */
    function initDependentFields(root) {
        root = root || document;
        const dependents = root.querySelectorAll('[data-depends-on]');

        function evalDependent(el) {
            const fieldName  = el.dataset.dependsOn;
            const wantValue  = el.dataset.dependsValue;
            const sourceEl   = root.querySelector(`[name="${CSS.escape(fieldName)}"]`);
            if (!sourceEl) return;

            let currentValue;
            if (sourceEl.type === 'checkbox') {
                currentValue = sourceEl.checked ? sourceEl.value : '';
            } else if (sourceEl.type === 'radio') {
                const checked = root.querySelector(`[name="${CSS.escape(fieldName)}"]:checked`);
                currentValue = checked ? checked.value : '';
            } else {
                currentValue = sourceEl.value;
            }

            const show = currentValue === wantValue;
            el.hidden = !show;

            // Disable required fields when hidden (prevents accidental form block)
            el.querySelectorAll('[required]').forEach(f => {
                f.dataset.wasRequired = f.dataset.wasRequired || 'true';
                f.required = show;
            });
        }

        dependents.forEach(el => {
            const fieldName = el.dataset.dependsOn;
            const sources   = root.querySelectorAll(`[name="${CSS.escape(fieldName)}"]`);

            // Initial evaluation
            evalDependent(el);

            // React to changes
            sources.forEach(src => {
                src.addEventListener('change', () => evalDependent(el));
                src.addEventListener('input',  () => evalDependent(el));
            });
        });
    }

    initDependentFields(document);

    // Re-init after modal opens (modals may contain dependent fields)
    document.addEventListener('modal:opened', e => {
        if (e.detail && e.detail.id) {
            const modal = document.getElementById(e.detail.id);
            if (modal) initDependentFields(modal);
        }
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// Spinner CSS injection (so the spinner works without extra stylesheet edits)
// ─────────────────────────────────────────────────────────────────────────────
(function injectSpinnerStyle() {
    if (document.getElementById('mztech-spinner-style')) return;
    const style = document.createElement('style');
    style.id = 'mztech-spinner-style';
    style.textContent = `
        .spinner {
            display: inline-block;
            width: .9em;
            height: .9em;
            border: 2px solid currentColor;
            border-top-color: transparent;
            border-radius: 50%;
            animation: mztech-spin .6s linear infinite;
            vertical-align: middle;
        }
        @keyframes mztech-spin { to { transform: rotate(360deg); } }

        input.field-error,
        select.field-error,
        textarea.field-error {
            border-color: #dc2626 !important;
            box-shadow: 0 0 0 3px rgba(220,38,38,.15) !important;
        }
    `;
    document.head.appendChild(style);
})();
