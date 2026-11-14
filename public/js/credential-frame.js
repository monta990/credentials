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

    // GLPI's createIframeModalWindow() initially sizes the iframe from the
    // full GLPI document scrollHeight. The GLPI page shell can be viewport-tall
    // even when this plugin's content is short, leaving a large empty modal.
    // Report the actual plugin content height to the parent after GLPI's own
    // iframe onload handler has run. The parent validates the iframe source and
    // clamps the requested height to the available viewport.
    const contentRoot = document.querySelector('.plugin_credentials');
    if (frame && contentRoot) {
        let resizeTimer = null;
        let lastReportedHeight = 0;

        const reportContentHeight = () => {
            const rect = contentRoot.getBoundingClientRect();
            const styles = window.getComputedStyle(contentRoot);
            const marginBottom = Number.parseFloat(styles.marginBottom) || 0;
            const documentTop = rect.top + (window.scrollY || window.pageYOffset || 0);
            const documentBottom = Math.max(
                rect.bottom + (window.scrollY || window.pageYOffset || 0),
                documentTop + contentRoot.scrollHeight,
            );
            const height = Math.ceil(documentBottom + marginBottom + 20);

            if (height < 160 || Math.abs(height - lastReportedHeight) < 2) {
                return;
            }

            lastReportedHeight = height;
            postToParent('resizeModal', { height });

            // Same-origin fallback: apply the measured height directly when the
            // parent page does not have the plugin message listener available.
            try {
                const activeModal = modal || frame.closest('.modal');
                if (activeModal && parentDocument && parentDocument.contains(frame)) {
                    const parentWindow = window.parent;
                    const viewportHeight = parentWindow.innerHeight
                        || parentDocument.documentElement?.clientHeight
                        || 700;
                    const maxFrameHeight = Math.max(220, Math.floor(viewportHeight - 120));
                    const safeHeight = Math.min(height, maxFrameHeight);
                    frame.style.height = safeHeight + 'px';
                    frame.style.maxHeight = 'calc(100vh - 7.5rem)';
                    frame.style.display = 'block';
                    frame.style.width = '100%';

                    const modalBody = activeModal.querySelector('.modal-body');
                    if (modalBody) {
                        modalBody.style.maxHeight = 'calc(100vh - 5rem)';
                        modalBody.style.overflowY = 'auto';
                    }
                    const modalInstance = parentWindow.bootstrap?.Modal?.getInstance(activeModal);
                    if (modalInstance && typeof modalInstance.handleUpdate === 'function') {
                        modalInstance.handleUpdate();
                    }
                }
            } catch (error) {
                // The validated parent message remains the fallback path.
            }
        };

        const scheduleContentHeight = () => {
            if (resizeTimer !== null) {
                window.clearTimeout(resizeTimer);
            }
            // Delay the message so GLPI's native iframe onload sizing runs first.
            resizeTimer = window.setTimeout(() => {
                resizeTimer = null;
                window.requestAnimationFrame(() => {
                    window.requestAnimationFrame(reportContentHeight);
                });
            }, 120);
        };

        window.addEventListener('load', scheduleContentHeight, { once: true });
        window.addEventListener('resize', scheduleContentHeight);
        if (document.readyState === 'complete') {
            scheduleContentHeight();
        }
        scheduleContentHeight();

        if (typeof window.ResizeObserver === 'function') {
            const resizeObserver = new window.ResizeObserver(scheduleContentHeight);
            resizeObserver.observe(contentRoot);
        }
        if (typeof window.MutationObserver === 'function') {
            const mutationObserver = new window.MutationObserver(scheduleContentHeight);
            mutationObserver.observe(contentRoot, {
                childList: true,
                subtree: true,
                characterData: true,
                attributes: true,
                attributeFilter: ['hidden', 'class', 'style'],
            });
        }
    }

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

    // Details pages already set their title in credential-details.js.
    // Other iframe pages use this helper as their title source.
    if (source && !document.querySelector('[data-credentials-detail-root="1"]')) {
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
