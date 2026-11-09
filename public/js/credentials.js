/**
 * GLPI Credentials plugin.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Page-level handlers only. Password controls are initialized by the details
 * dedicated external iframe script because GLPI's iframe modals do not guarantee
 * that the host page's plugin JavaScript hook is loaded in the child context.
 */

(() => {
    if (window.__credentialsModalHandlersInstalled) {
        return;
    }
    window.__credentialsModalHandlersInstalled = true;

    // Iframe pages cannot reliably invoke GLPI's parent Bootstrap instance from
    // every browser context. Accept only same-origin messages from an iframe that
    // is physically contained in one of the Credentials dialogs.
    if (!window.__credentialsFrameMessagesInstalled && typeof window.addEventListener === 'function') {
        window.__credentialsFrameMessagesInstalled = true;
        window.addEventListener('message', (event) => {
            if (event.origin !== window.location.origin || !event.data || event.data.source !== 'glpi-credentials') {
                return;
            }

            const modal = Array.from(document.querySelectorAll('.modal')).find((candidate) => {
                const iframe = candidate.querySelector('iframe');
                return iframe && iframe.contentWindow === event.source;
            });
            if (!modal) return;

            if (event.data.action === 'navigateTop') {
                if (typeof event.data.url !== 'string' || event.data.url === '') return;
                let destination;
                try {
                    destination = new URL(event.data.url, window.location.href);
                } catch (error) {
                    return;
                }
                if (destination.origin !== window.location.origin || destination.username || destination.password) {
                    return;
                }
                window.location.assign(destination.href);
                return;
            }

            if (event.data.action === 'setModalTitle') {
                const title = modal.querySelector('.modal-title');
                if (!title || typeof event.data.title !== 'string') return;
                const iconName = typeof event.data.icon === 'string' && /^ti-[a-z0-9-]+$/i.test(event.data.icon)
                    ? event.data.icon
                    : 'ti-key';
                const icon = document.createElement('i');
                icon.className = 'ti ' + iconName + ' credentials-modal-title-icon me-2';
                icon.setAttribute('aria-hidden', 'true');
                title.replaceChildren(icon, document.createTextNode(event.data.title));
                return;
            }

            if (event.data.action === 'closeModal') {
                const dismiss = modal.querySelector('.modal-header [data-bs-dismiss="modal"], .modal-header .btn-close');
                if (dismiss) {
                    dismiss.click();
                } else if (window.bootstrap?.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(modal).hide();
                }
            }
        });
    }

    if (!window.__credentialsModalRefreshInstalled) {
        window.__credentialsModalRefreshInstalled = true;

        document.addEventListener('show.bs.modal', (event) => {
            const modal = event.target instanceof HTMLElement ? event.target : null;
            const trigger = event.relatedTarget instanceof HTMLElement ? event.relatedTarget : null;
            if (!modal || !modal.id.startsWith('credentials_')) return;
            const countUrl = trigger?.dataset.credentialsCountUrl;
            if (countUrl) modal.dataset.credentialsCountUrl = countUrl;
        });

        document.addEventListener('hidden.bs.modal', async (event) => {
            const modal = event.target instanceof HTMLElement ? event.target : null;
            if (!modal || !modal.id.startsWith('credentials_') || modal.dataset.credentialsChanged !== '1') {
                return;
            }
            delete modal.dataset.credentialsChanged;
            if (typeof window.reloadTab === 'function') {
                window.reloadTab('credentials_refresh=' + Date.now());
            }

            const countUrl = modal.dataset.credentialsCountUrl;
            if (!countUrl || typeof window.fetch !== 'function') return;
            try {
                const response = await window.fetch(countUrl, {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const contentType = response.headers.get('content-type') || '';
                if (!response.ok || !contentType.toLowerCase().includes('application/json')) return;
                const payload = await response.json();
                if (payload.success !== true || !Number.isInteger(payload.count) || payload.count < 0) return;

                const activeTab = document.querySelector('#tabspanel .nav-link.active, #tabspanel a.active[role="tab"], .nav-tabs .nav-link.active');
                if (!activeTab) return;
                const badge = activeTab.querySelector('[data-testid="tab-count-badge"], .glpi-badge');
                if (payload.count === 0) {
                    badge?.remove();
                } else if (badge) {
                    badge.textContent = String(payload.count);
                } else {
                    const newBadge = document.createElement('span');
                    newBadge.className = 'badge glpi-badge';
                    newBadge.dataset.testid = 'tab-count-badge';
                    newBadge.textContent = String(payload.count);
                    activeTab.append(' ', newBadge);
                }
            } catch (error) {
                // The tab body has already refreshed. A count failure must not break the item page.
            }
        });
    }

    // Confirm mutations rendered in the parent AJAX tab. Forms inside the
    // credential details iframe own their confirmation handler locally.
    document.addEventListener('submit', (event) => {
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        if (!form || form.dataset.credentialsConfirmInline === '1') {
            return;
        }
        const confirmation = form.dataset.credentialsConfirm;
        if (typeof confirmation === 'string' && confirmation !== '' && !window.confirm(confirmation)) {
            event.preventDefault();
            event.stopPropagation();
        }
    }, true);
})();
