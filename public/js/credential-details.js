/**
 * GLPI Credentials plugin — credential details iframe behavior.
 * Copyright (C) 2026 Edwin Elias Alvarez.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
(() => {
    'use strict';

    const root = document.querySelector('[data-credentials-detail-root="1"]');
    if (!root || root.dataset.initialized === '1') return;
    root.dataset.initialized = '1';

    const getModal = () => {
        try {
            const frame = window.frameElement;
            return frame ? frame.closest('.modal') : null;
        } catch (error) {
            return null;
        }
    };

    const modal = getModal();
    const titleSource = document.getElementById('credentials-modal-title-source');
    if (titleSource) {
        const titleMessage = {
            source: 'glpi-credentials',
            action: 'setModalTitle',
            title: titleSource.textContent.trim(),
            icon: titleSource.dataset.icon || 'ti-key',
        };
        try {
            window.parent.postMessage(titleMessage, window.location.origin);
            const title = modal?.querySelector('.modal-title');
            const targetDocument = window.parent.document;
            if (title && targetDocument) {
                const icon = targetDocument.createElement('i');
                icon.className = 'ti ' + (titleSource.dataset.icon || 'ti-key') + ' credentials-modal-title-icon me-2';
                icon.setAttribute('aria-hidden', 'true');
                title.replaceChildren(icon, targetDocument.createTextNode(titleSource.textContent.trim()));
            }
        } catch (error) {
            // Parent message handler is the primary path; native title remains as fallback.
        }
    }

    if (modal && new URLSearchParams(window.location.search).get('changed') === '1') {
        modal.dataset.credentialsChanged = '1';
    }

    const revealForm = root.querySelector('[data-credentials-reveal-form="1"]');
    const revealButton = revealForm?.querySelector('[data-credentials-reveal-button="1"]');
    const revealedPanel = root.querySelector('#credentials-password-revealed');
    const passwordValue = root.querySelector('#credentials-revealed-value');
    const status = root.querySelector('#credentials-password-status');
    const copyButton = root.querySelector('#credentials-copy-password');
    const hideButton = root.querySelector('#credentials-hide-password');

    const showStatus = (message, kind = 'info') => {
        if (!status) return;
        status.textContent = message;
        status.dataset.kind = kind;
        status.className = 'credentials-password-status credentials-password-status-' + kind;
        status.hidden = !message;
    };

    // Reveal is intentionally a normal POST to the detail page. GLPI 12 cannot
    // display its native re-authentication prompt for AJAX requests; the iframe
    // POST lets the core prompt/replay the request and renders the secret with
    // no-store headers. Hide and Copy remain local DOM actions.
    if (revealForm && revealButton) {
        revealButton.type = 'submit';
        revealForm.addEventListener('submit', () => {
            // The hidden reveal_password input is always submitted, even when the
            // button is disabled here to prevent duplicate requests.
            revealButton.disabled = true;
            revealButton.setAttribute('aria-busy', 'true');
            const loadingMessage = revealButton.dataset.loadingMessage;
            if (loadingMessage) showStatus(loadingMessage, 'info');
        }, { once: true });
    }

    const readPassword = () => {
        if (!passwordValue) return '';
        return typeof passwordValue.value === 'string' ? passwordValue.value : (passwordValue.textContent || '');
    };

    const clearPassword = () => {
        if (passwordValue) {
            if (typeof passwordValue.value === 'string') {
                passwordValue.value = '';
            } else {
                passwordValue.textContent = '';
            }
        }
        if (revealedPanel) revealedPanel.hidden = true;
        if (revealForm) revealForm.hidden = false;
        if (revealButton) {
            revealButton.disabled = false;
            revealButton.removeAttribute('aria-busy');
        }
    };

    const writeClipboard = async (value) => {
        const candidates = [];
        try {
            if (window.parent?.navigator?.clipboard?.writeText) candidates.push(window.parent.navigator.clipboard);
        } catch (error) {
            // Clipboard permissions may differ between the host and iframe.
        }
        try {
            if (window.navigator?.clipboard?.writeText) candidates.push(window.navigator.clipboard);
        } catch (error) {
            // Continue to the execCommand fallback.
        }

        for (const clipboard of candidates) {
            try {
                await clipboard.writeText(value);
                return true;
            } catch (error) {
                // Try the next context before falling back.
            }
        }

        const temporary = document.createElement('textarea');
        temporary.value = value;
        temporary.setAttribute('readonly', 'readonly');
        temporary.setAttribute('aria-hidden', 'true');
        temporary.style.position = 'fixed';
        temporary.style.top = '0';
        temporary.style.left = '-10000px';
        document.body.appendChild(temporary);
        temporary.focus();
        temporary.select();
        let copied = false;
        try {
            copied = document.execCommand('copy') === true;
        } catch (error) {
            copied = false;
        }
        temporary.remove();
        return copied;
    };

    if (copyButton && passwordValue) {
        copyButton.addEventListener('click', async (event) => {
            event.preventDefault();
            const value = readPassword();
            if (!value) {
                showStatus(copyButton.dataset.copyEmptyMessage || 'Primero revela la contraseña.', 'warning');
                return;
            }
            copyButton.disabled = true;
            try {
                if (await writeClipboard(value)) {
                    showStatus(copyButton.dataset.copiedMessage || 'Contraseña copiada.', 'success');
                } else {
                    showStatus(copyButton.dataset.copyDeniedMessage || 'No se pudo acceder al portapapeles. Selecciona y copia la contraseña manualmente.', 'warning');
                    passwordValue.focus();
                    if (typeof passwordValue.select === 'function') {
                        passwordValue.select();
                    } else {
                        const selection = document.createRange();
                        selection.selectNodeContents(passwordValue);
                        window.getSelection()?.removeAllRanges();
                        window.getSelection()?.addRange(selection);
                    }
                }
            } finally {
                copyButton.disabled = false;
            }
        });
    }

    if (hideButton) {
        hideButton.addEventListener('click', (event) => {
            event.preventDefault();
            clearPassword();
            showStatus(hideButton.dataset.hiddenMessage || 'Contraseña oculta.', 'info');
            revealButton?.focus();
        });
    }

    root.querySelectorAll('form[data-credentials-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.credentialsConfirm || '¿Confirmas esta operación?')) {
                event.preventDefault();
                event.stopPropagation();
            }
        });
    });

    window.addEventListener('pagehide', clearPassword, { once: true });
})();
