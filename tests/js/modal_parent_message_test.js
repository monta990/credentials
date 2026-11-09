#!/usr/bin/env node
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/credentials.js'), 'utf8');

const listeners = {};
let hideClicks = 0;
const title = { replaceChildren(...nodes) { this.nodes = nodes; } };
const dismiss = { click() { hideClicks++; } };
const frameWindow = {};
const iframe = { contentWindow: frameWindow };
const modal = { querySelector(selector) {
    if (selector === 'iframe') return iframe;
    if (selector === '.modal-title') return title;
    if (selector === '.modal-header [data-bs-dismiss="modal"], .modal-header .btn-close') return dismiss;
    return null;
} };
const document = {
    querySelectorAll(selector) { return selector === '.modal' ? [modal] : []; },
    createElement(name) { return { tagName: name, className: '', setAttribute() {} }; },
    createTextNode(text) { return { text }; },
    addEventListener() {},
};
const navigations = [];
const window = {
    __credentialsModalHandlersInstalled: false,
    location: { origin: 'https://glpi.example.test', href: 'https://glpi.example.test/front/user.form.php?id=2', assign(url) { navigations.push(url); } },
    addEventListener(type, callback) { listeners[type] = callback; },
};
const HTMLElement = class HTMLElement {};
const HTMLFormElement = class HTMLFormElement {};
vm.runInNewContext(source, { window, document, HTMLElement, HTMLFormElement, console, Date, URL });
if (typeof listeners.message !== 'function') throw new Error('Parent must install iframe message listener.');

listeners.message({
    origin: 'https://evil.example',
    source: frameWindow,
    data: { source: 'glpi-credentials', action: 'closeModal' },
});
if (hideClicks !== 0) throw new Error('Cross-origin messages must be ignored.');

listeners.message({
    origin: 'https://glpi.example.test',
    source: {},
    data: { source: 'glpi-credentials', action: 'closeModal' },
});
if (hideClicks !== 0) throw new Error('Messages not sent by a modal iframe must be ignored.');

listeners.message({
    origin: 'https://glpi.example.test',
    source: frameWindow,
    data: { source: 'glpi-credentials', action: 'setModalTitle', title: 'Agregar credencial', icon: 'ti-plus' },
});
if (title.nodes?.length !== 2 || title.nodes[1]?.text !== 'Agregar credencial' || !title.nodes[0].className.includes('ti-plus') || !title.nodes[0].className.includes('credentials-modal-title-icon')) {
    throw new Error('Parent must set the modal title with a validated Tabler icon.');
}

listeners.message({
    origin: 'https://glpi.example.test',
    source: frameWindow,
    data: { source: 'glpi-credentials', action: 'closeModal' },
});
if (hideClicks !== 1) throw new Error('Cancel message must invoke GLPI native modal dismiss button.');

listeners.message({
    origin: 'https://glpi.example.test',
    source: frameWindow,
    data: { source: 'glpi-credentials', action: 'navigateTop', url: '/front/user.form.php?id=2' },
});
if (navigations.length !== 1 || navigations[0] !== 'https://glpi.example.test/front/user.form.php?id=2') {
    throw new Error('Save must navigate the top-level GLPI page through the validated iframe bridge.');
}
listeners.message({
    origin: 'https://glpi.example.test',
    source: frameWindow,
    data: { source: 'glpi-credentials', action: 'navigateTop', url: 'https://evil.example/phish' },
});
if (navigations.length !== 1) throw new Error('The parent must reject cross-origin top-level navigation requests.');
console.log('GLPI parent iframe message bridge: origin check, iframe binding, modal title icon, close and safe top navigation: OK');
