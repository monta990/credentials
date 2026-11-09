#!/usr/bin/env node
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/credential-frame.js'), 'utf8');
const sent = [];
const document = {
    body: { dataset: { credentialsNavigateTop: '/front/user.form.php?id=2' } },
    getElementById() { return null; },
    querySelectorAll() { return []; },
};
const window = {
    location: { origin: 'https://glpi.example.test', href: 'https://glpi.example.test/plugins/credentials/Credential/Save' },
    parent: { postMessage(message, origin) { sent.push({ message, origin }); }, document: {} },
};
vm.runInNewContext(source, { window, document, URL, console });
if (sent.length !== 1 || sent[0].message.action !== 'navigateTop'
    || sent[0].message.url !== 'https://glpi.example.test/front/user.form.php?id=2'
    || sent[0].origin !== 'https://glpi.example.test') {
    throw new Error('The save response must request a same-origin top-level navigation through the iframe bridge.');
}
document.body.dataset.credentialsNavigateTop = 'https://evil.example/steal';
sent.length = 0;
vm.runInNewContext(source, { window, document, URL, console });
if (sent.length !== 0) throw new Error('The iframe helper must reject cross-origin navigation targets.');
console.log('Save response iframe helper: same-origin navigation only: OK');
