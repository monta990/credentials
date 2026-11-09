'use strict';

const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const template = fs.readFileSync(path.join(__dirname, '../../templates/credential/details.html.twig'), 'utf8');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/credential-details.js'), 'utf8');

const expectedForms = (template.match(/<form[^>]+data-credentials-confirm=/g) || []).length;
if (expectedForms < 2 || template.includes('data-credentials-confirm-inline')) {
    throw new Error('Both Unlink and Delete forms in the iframe must expose the credential-details confirmation attribute.');
}

function createForm(message) {
    const listeners = {};
    return {
        dataset: { credentialsConfirm: message },
        addEventListener(type, callback) { listeners[type] = callback; },
        submit(event) { listeners.submit(event); },
    };
}

const deleteForm = createForm('Delete this credential for all linked items? This cannot be undone.');
const unlinkForm = createForm('Unlink this credential from the current item?');
const root = {
    dataset: {},
    querySelector() { return null; },
    querySelectorAll(selector) {
        return selector === 'form[data-credentials-confirm]' ? [unlinkForm, deleteForm] : [];
    },
};
const document = {
    querySelector(selector) { return selector === '[data-credentials-detail-root="1"]' ? root : null; },
    getElementById() { return null; },
};
let confirmationResult = false;
const window = {
    frameElement: null,
    location: { search: '?_in_modal=1' },
    confirm(message) { this.lastConfirmation = message; return confirmationResult; },
    addEventListener() {},
};

vm.runInNewContext(source, { window, document, console, URLSearchParams });

function dispatch(form) {
    let prevented = false;
    let stopped = false;
    form.submit({
        preventDefault() { prevented = true; },
        stopPropagation() { stopped = true; },
    });
    return { prevented, stopped };
}

confirmationResult = false;
for (const form of [unlinkForm, deleteForm]) {
    const outcome = dispatch(form);
    if (!outcome.prevented || !outcome.stopped) {
        throw new Error('Canceling an iframe destructive confirmation must block the POST.');
    }
}

confirmationResult = true;
for (const form of [unlinkForm, deleteForm]) {
    const outcome = dispatch(form);
    if (outcome.prevented || outcome.stopped) {
        throw new Error('Confirming an iframe destructive action must allow the POST.');
    }
}

console.log('Credential details iframe unlink/delete confirmation regression: OK');
