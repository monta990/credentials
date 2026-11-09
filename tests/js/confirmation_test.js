'use strict';

const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const listeners = {};
let confirmationResult = false;
const document = {
    addEventListener(type, callback) {
        listeners[type] = callback;
    },
};
const window = {
    __credentialsModalHandlersInstalled: false,
    __credentialsModalRefreshInstalled: false,
    confirm: () => confirmationResult,
    alert: () => {},
};
class HTMLFormElement {
    constructor(message) {
        this.dataset = { credentialsConfirm: message };
    }
}
class Element {}
class HTMLElement extends Element {}

const source = fs.readFileSync(path.join(__dirname, '../../public/js/credentials.js'), 'utf8');
vm.runInNewContext(source, {
    window,
    document,
    Element,
    HTMLElement,
    HTMLFormElement,
    navigator: {},
    Date,
    console,
});

if (typeof listeners.submit !== 'function') {
    throw new Error('The delegated submit listener was not installed.');
}

let prevented = false;
let stopped = false;
confirmationResult = false;
listeners.submit({
    target: new HTMLFormElement('Delete?'),
    preventDefault() { prevented = true; },
    stopPropagation() { stopped = true; },
});
if (!prevented || !stopped) {
    throw new Error('Declining confirmation must prevent the destructive submission.');
}

prevented = false;
stopped = false;
confirmationResult = true;
listeners.submit({
    target: new HTMLFormElement('Delete?'),
    preventDefault() { prevented = true; },
    stopPropagation() { stopped = true; },
});
if (prevented || stopped) {
    throw new Error('Accepting confirmation must allow the form submission.');
}

console.log('Destructive-action confirmation regression: OK');
