'use strict';

const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const template = fs.readFileSync(path.join(__dirname, '../../templates/credential/details.html.twig'), 'utf8');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/credential-details.js'), 'utf8');
if (!template.includes('type="submit"') || !template.includes('<input type="hidden" name="reveal_password" value="1">')) {
    throw new Error('Reveal must submit a normal POST so GLPI can run its native re-authentication prompt.');
}
if (!template.includes('action="{{ detail_url|e(\'html_attr\') }}"')) {
    throw new Error('Reveal must POST to the current credential detail route, not an AJAX-only route.');
}
if (!template.includes('type="text"') || !template.includes('readonly') || !template.includes('id="credentials-revealed-value"')) {
    throw new Error('Revealed secret must be rendered in a selectable readonly text input.');
}
const css = fs.readFileSync(path.join(__dirname, '../../public/css/credentials.css'), 'utf8');
if (!css.includes('-webkit-text-fill-color: var(--tblr-body-color') || !css.includes('opacity: 1 !important')) {
    throw new Error('Revealed password input must force visible text in Chromium and GLPI themes.');
}
if (!template.includes('data-icon="ti-key"')) {
    throw new Error('Credential detail modal title must use a visible Tabler key icon.');
}
if (template.includes('/ajax/Credential/Reveal') || source.includes('fetch(revealForm.action')) {
    throw new Error('AJAX reveal must not bypass GLPI native re-authentication.');
}

function createHarness(clipboardWrite = async () => {}) {
    const listeners = {};
    const copyListeners = {};
    const hideListeners = {};
    const status = { textContent: '', className: '', dataset: {}, hidden: false };
    const passwordValue = { value: 'Strong-Secret-123', focus() { this.focused = true; }, select() { this.selected = true; } };
    const revealButton = { type: 'submit', disabled: false, dataset: { loadingMessage: 'Requesting…' }, focus() { this.focused = true; }, setAttribute(name, value) { this[name] = value; }, removeAttribute(name) { delete this[name]; } };
    const revealForm = { hidden: true, dataset: {}, querySelector() { return revealButton; }, addEventListener(type, callback) { listeners['revealForm:' + type] = callback; } };
    const revealedPanel = { hidden: false };
    const copyButton = {
        dataset: { copiedMessage: 'Copied', copyDeniedMessage: 'Copy denied', copyEmptyMessage: 'Reveal first' },
        disabled: false,
        addEventListener(type, callback) { copyListeners[type] = callback; },
    };
    const hideButton = {
        dataset: { hiddenMessage: 'Hidden' },
        addEventListener(type, callback) { hideListeners[type] = callback; },
        focus() { this.focused = true; },
    };
    const root = {
        dataset: {},
        querySelector(selector) {
            return ({
                '[data-credentials-reveal-form="1"]': revealForm,
                '#credentials-password-revealed': revealedPanel,
                '#credentials-revealed-value': passwordValue,
                '#credentials-password-status': status,
                '#credentials-copy-password': copyButton,
                '#credentials-hide-password': hideButton,
            })[selector] || null;
        },
        querySelectorAll() { return []; },
    };
    const title = { replaceChildren(...children) { this.children = children; } };
    const modal = { dataset: {}, querySelector(selector) { return selector === '.modal-title' ? title : null; } };
    const parentDocument = { createElement: () => ({ className: '', setAttribute() {} }), createTextNode: (text) => ({ text }) };
    const frame = { closest(selector) { return selector === '.modal' ? modal : null; } };
    const document = {
        body: { appendChild(node) { node.parentNode = this; } },
        querySelector(selector) { return selector === '[data-credentials-detail-root="1"]' ? root : null; },
        getElementById(selector) { return selector === 'credentials-modal-title-source' ? { dataset: { icon: 'ti-key' }, textContent: 'Credential details' } : null; },
        createElement(name) { return { tagName: name, style: {}, value: '', setAttribute() {}, focus() {}, select() {}, remove() { this.removed = true; } }; },
        addEventListener(type, callback) { listeners['document:' + type] = callback; },
        execCommand(command) { return command === 'copy'; },
        createRange() { return { selectNodeContents() {} }; },
    };
    let clipboardValue = '';
    const window = {
        frameElement: frame,
        location: { search: '?_in_modal=1' },
        parent: { document: parentDocument, navigator: { clipboard: { writeText: async (value) => { clipboardValue = value; await clipboardWrite(value); } } } },
        navigator: {},
        addEventListener(type, callback) { listeners['window:' + type] = callback; },
        getSelection() { return { removeAllRanges() {}, addRange() {} }; },
        confirm: () => true,
    };
    vm.runInNewContext(source, { window, document, URLSearchParams, HTMLElement: class HTMLElement {}, console });
    return { listeners, copyListeners, hideListeners, revealForm, revealButton, revealedPanel, passwordValue, status, copyButton, hideButton, modal, window, get clipboardValue() { return clipboardValue; } };
}

(async () => {
    const success = createHarness();
    if (success.revealButton.type !== 'submit' || !success.revealForm.hidden || success.revealedPanel.hidden) {
        throw new Error('A server-rendered reveal response must show the secret and hide the POST control.');
    }
    success.listeners['revealForm:submit']?.();
    if (!success.revealButton.disabled || success.revealButton['aria-busy'] !== 'true' || success.status.textContent !== 'Requesting…') {
        throw new Error('Reveal submit must provide busy feedback and prevent duplicate clicks.');
    }
    await success.copyListeners.click({ preventDefault() {} });
    if (success.clipboardValue !== 'Strong-Secret-123' || success.status.textContent !== 'Copied') {
        throw new Error('Copy must copy the server-rendered value and show success feedback.');
    }
    success.hideListeners.click({ preventDefault() {} });
    if (success.passwordValue.value !== '' || !success.revealedPanel.hidden || success.revealForm.hidden || success.status.textContent !== 'Hidden' || success.revealButton['aria-busy'] !== undefined) {
        throw new Error('Hide must clear plaintext and restore the native POST reveal control.');
    }
    success.passwordValue.value = 'Sensitive';
    success.listeners['window:pagehide']?.();
    if (success.passwordValue.value !== '') throw new Error('Pagehide must clear plaintext.');

    const denied = createHarness(async () => { throw new Error('clipboard denied'); });
    denied.window.navigator.clipboard = { writeText: async () => { throw new Error('denied'); } };
    await denied.copyListeners.click({ preventDefault() {} });
    if (denied.status.textContent !== 'Copied') {
        throw new Error('Copy must use the iframe fallback when Clipboard API rejects.');
    }

    console.log('Password UI: normal POST, server-rendered reveal, copy fallback, hide and pagehide: OK');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
