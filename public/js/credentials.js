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

            if (event.data.action === 'resizeModal') {
                const iframe = modal.querySelector('iframe[data-testid="modal-iframe"], iframe');
                const requestedHeight = Number(event.data.height);
                if (
                    !iframe
                    || iframe.contentWindow !== event.source
                    || !Number.isFinite(requestedHeight)
                    || requestedHeight < 160
                ) {
                    return;
                }

                // Leave room for the native modal header, padding and screen edges.
                // Long forms remain scrollable inside the iframe on smaller screens.
                const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 700;
                const maxFrameHeight = Math.max(220, Math.floor(viewportHeight - 120));
                const frameHeight = Math.min(Math.ceil(requestedHeight), maxFrameHeight);
                iframe.style.height = frameHeight + 'px';
                iframe.style.maxHeight = 'calc(100vh - 7.5rem)';
                iframe.style.display = 'block';
                iframe.style.width = '100%';

                const modalBody = modal.querySelector('.modal-body');
                if (modalBody) {
                    modalBody.style.maxHeight = 'calc(100vh - 5rem)';
                    modalBody.style.overflowY = 'auto';
                }

                const modalInstance = window.bootstrap?.Modal?.getInstance(modal);
                if (modalInstance && typeof modalInstance.handleUpdate === 'function') {
                    modalInstance.handleUpdate();
                }
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

    // Category-first and name filtering for the reusable credential selector.
    // Keep the select native so GLPI styling and browser keyboard navigation work.
    const filterLinkableCredentials = (form) => {
        if (!(form instanceof HTMLFormElement)) return;
        const categorySelect = form.querySelector('[data-credentials-category-filter]');
        const nameFilter = form.querySelector('[data-credentials-name-filter]');
        const credentialSelect = form.querySelector('[name="credential_id"]');
        if (!(categorySelect instanceof HTMLSelectElement) || !(credentialSelect instanceof HTMLSelectElement)) return;

        const categoryId = categorySelect.value;
        const search = nameFilter instanceof HTMLInputElement ? nameFilter.value.trim().toLocaleLowerCase() : '';
        for (const option of credentialSelect.options) {
            if (option.value === '') continue;
            const categoryMatches = categoryId === 'all' || option.dataset.categoryId === categoryId;
            const nameMatches = search === '' || option.textContent.toLocaleLowerCase().includes(search);
            const matches = categoryMatches && nameMatches;
            option.hidden = !matches;
            option.disabled = !matches;
        }

        const selected = credentialSelect.selectedOptions[0];
        if (selected && (selected.hidden || selected.disabled)) {
            credentialSelect.value = '';
        }
        credentialSelect.dispatchEvent(new Event('change', { bubbles: true }));
    };

    document.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLSelectElement) || !target.matches('[data-credentials-category-filter]')) return;
        filterLinkableCredentials(target.closest('form'));
    });

    document.addEventListener('input', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement) || !target.matches('[data-credentials-name-filter]')) return;
        filterLinkableCredentials(target.closest('form'));
    });

    // Server-side, permission-scoped lookup for archived-credential restore.
    // The endpoint returns a small bounded JSON array; names are assigned with
    // textContent so item labels cannot become markup.
    const restoreSearchControllers = new WeakMap();
    document.addEventListener('input', async (event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || !input.matches('[data-credentials-restore-query]')) return;
        const wrapper = input.closest('[data-credentials-restore-search]');
        if (!wrapper) return;
        const select = wrapper.querySelector('[data-credentials-restore-results]');
        const status = wrapper.querySelector('[data-credentials-restore-status]');
        const submit = wrapper.parentElement?.querySelector('[data-credentials-restore-submit]');
        if (!(select instanceof HTMLSelectElement)) return;

        const query = input.value.trim();
        select.replaceChildren(new Option(wrapper.dataset.searchEmpty || '', ''));
        if (submit instanceof HTMLButtonElement) submit.disabled = true;
        const previous = restoreSearchControllers.get(wrapper);
        if (previous) previous.abort();
        if (query.length < 2) {
            if (status) status.textContent = wrapper.dataset.searchTooShort || '';
            return;
        }

        const controller = new AbortController();
        restoreSearchControllers.set(wrapper, controller);
        if (status) status.textContent = wrapper.dataset.searching || '';
        try {
            const url = new URL(wrapper.dataset.restoreSearchUrl || '', window.location.href);
            if (url.origin !== window.location.origin) throw new Error('Invalid search endpoint');
            url.searchParams.set('q', query);
            const response = await fetch(url.href, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal,
            });
            const contentType = response.headers.get('content-type') || '';
            if (!response.ok || !contentType.toLowerCase().includes('application/json')) {
                throw new Error('Search request failed');
            }
            const payload = await response.json();
            if (restoreSearchControllers.get(wrapper) !== controller) return;
            const items = Array.isArray(payload.items) ? payload.items : [];
            for (const item of items) {
                if (!item || typeof item.value !== 'string' || typeof item.label !== 'string') continue;
                const option = document.createElement('option');
                option.value = item.value;
                option.textContent = item.label;
                select.append(option);
            }
            if (status) status.textContent = items.length > 0
                ? (wrapper.dataset.searchResults || '').replace('%count%', String(items.length))
                : (wrapper.dataset.searchNone || '');
        } catch (error) {
            if (error?.name === 'AbortError') return;
            if (status) status.textContent = wrapper.dataset.searchFailed || '';
        }
    });

    document.addEventListener('change', (event) => {
        const select = event.target;
        if (!(select instanceof HTMLSelectElement) || !select.matches('[data-credentials-restore-results]')) return;
        const wrapper = select.closest('[data-credentials-restore-search]');
        const submit = wrapper?.parentElement?.querySelector('[data-credentials-restore-submit]');
        if (submit instanceof HTMLButtonElement) submit.disabled = select.value === '';
    });

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
