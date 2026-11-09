/** GLPI Credentials iframe modal helpers. */
(() => {
    'use strict';

    const source = document.getElementById('credentials-modal-title-source');
    const frame = (() => {
        try { return window.frameElement || null; } catch (error) { return null; }
    })();
    const parentDocument = (() => {
        try { return window.parent.document; } catch (error) { return null; }
    })();
    const modal = (() => {
        try {
            if (frame) return frame.closest('.modal');
            return parentDocument?.querySelector('.modal.show iframe[data-testid="modal-iframe"]')?.closest('.modal') || null;
        } catch (error) {
            return null;
        }
    })();

    const postToParent = (action, data = {}) => {
        try {
            window.parent.postMessage({
                source: 'glpi-credentials',
                action,
                ...data,
            }, window.location.origin);
            return true;
        } catch (error) {
            return false;
        }
    };

    const navigationUrl = document.body?.dataset.credentialsNavigateTop;
    if (navigationUrl) {
        try {
            const destination = new URL(navigationUrl, window.location.href);
            if (destination.origin === window.location.origin && !destination.username && !destination.password) {
                postToParent('navigateTop', { url: destination.href });
            }
        } catch (error) {
            // Invalid navigation targets are ignored; never navigate to an external URL.
        }
    }

    if (source) {
        postToParent('setModalTitle', {
            title: source.textContent.trim(),
            icon: source.dataset.icon || 'ti-key',
        });

        // Same-origin direct update is a fallback if the host listener is not available.
        try {
            const title = modal?.querySelector('.modal-title');
            if (title && parentDocument) {
                const iconName = /^ti-[a-z0-9-]+$/i.test(source.dataset.icon || '') ? source.dataset.icon : 'ti-key';
                const icon = parentDocument.createElement('i');
                icon.className = `ti ${iconName} credentials-modal-title-icon me-2`;
                icon.setAttribute('aria-hidden', 'true');
                title.replaceChildren(icon, parentDocument.createTextNode(source.textContent.trim()));
            }
        } catch (error) {
            // The native modal title remains usable if direct access is unavailable.
        }
    }

    document.querySelectorAll('[data-credentials-close-modal="1"]').forEach((button) => {
        if (button.dataset.credentialsCloseBound === '1') return;
        button.dataset.credentialsCloseBound = '1';
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();

            // Prefer GLPI's own native dismiss button in the parent document.
            try {
                const activeModal = modal || frame?.closest('.modal');
                const dismiss = activeModal?.querySelector('.modal-header [data-bs-dismiss="modal"], .modal-header .btn-close');
                if (dismiss) {
                    dismiss.click();
                    return;
                }
                const bootstrap = window.parent.bootstrap;
                if (activeModal && bootstrap?.Modal) {
                    bootstrap.Modal.getOrCreateInstance(activeModal).hide();
                    return;
                }
            } catch (error) {
                // Fall through to the host page message handler.
            }
            postToParent('closeModal');
        });
    });
})();
