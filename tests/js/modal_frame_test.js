'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/credential-frame.js'), 'utf8');

const formTemplate = fs.readFileSync(path.join(__dirname, '../../templates/credential/form.html.twig'), 'utf8');
if (!formTemplate.includes('{% if is_in_modal %}') || !formTemplate.includes('data-credentials-close-modal="1"')) {
    throw new Error('Modal Cancel must always be a non-submit close action, not a link that behaves like Save.');
}
if (!formTemplate.includes('name="save" value="1"')) {
    throw new Error('Only the Save button may submit the credential form.');
}
const listeners = {};
let hideCalls = 0;
const modalTitle = { replaceChildren(...nodes) { this.nodes = nodes; } };
const modal = { querySelector(selector) { return selector === '.modal-title' ? modalTitle : null; } };
const button = { dataset: {}, addEventListener(type, callback) { listeners[type] = callback; } };
const titleSource = { dataset: { icon: 'ti-pencil' }, textContent: 'Editar credencial — 1111' };
const frame = { closest(selector) { return selector === '.modal' ? modal : null; } };
const parentDocument = {
    createElement() { return { className: '', setAttribute() {} }; },
    createTextNode(text) { return { text }; },
};
const document = {
    getElementById(id) { return id === 'credentials-modal-title-source' ? titleSource : null; },
    querySelectorAll(selector) { return selector === '[data-credentials-close-modal="1"]' ? [button] : []; },
};
const window = {
    frameElement: frame,
    parent: {
        document: parentDocument,
        bootstrap: { Modal: { getOrCreateInstance(target) { if (target !== modal) throw new Error('Wrong modal'); return { hide() { hideCalls++; } }; } } },
    },
};
vm.runInNewContext(source, { window, document, console });
if (modalTitle.nodes?.length !== 2 || modalTitle.nodes[1]?.text !== titleSource.textContent) {
    throw new Error('Iframe must update the native GLPI title with its Tabler icon.');
}
if (typeof listeners.click !== 'function') throw new Error('Cancel close handler was not installed.');
let prevented = false;
listeners.click({ preventDefault() { prevented = true; }, stopPropagation() {} });
if (!prevented || hideCalls !== 1) throw new Error('Cancel must close the current dialog without navigating or changing its parent.');
console.log('GLPI iframe form modal title and cancel regression: OK');
