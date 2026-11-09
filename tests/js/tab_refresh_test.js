'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/credentials.js'), 'utf8');
const listeners = {};
const badge = { textContent: '1', removed: false, remove() { this.removed = true; } };
const activeTab = {
    querySelector(selector) { return selector.includes('tab-count-badge') || selector.includes('glpi-badge') ? badge : null; },
    append() { throw new Error('Existing badge should be updated, not duplicated.'); },
};
const document = {
    addEventListener(type, callback) { listeners[type] = callback; },
    querySelector(selector) { return selector.startsWith('#tabspanel') || selector.startsWith('.nav-tabs') ? activeTab : null; },
    createElement() { return { dataset: {}, className: '', textContent: '' }; },
};
class HTMLElement { constructor(id, dataset = {}) { this.id = id; this.dataset = dataset; } querySelector() { return badge; } append() {} }
const modal = new HTMLElement('credentials_view_User_2_17', { credentialsChanged: '1' });
let reloadCalls = 0;
let fetchOptions = null;
const window = {
    __credentialsModalHandlersInstalled: false,
    __credentialsModalRefreshInstalled: false,
    reloadTab(query) { reloadCalls++; if (!query.includes('credentials_refresh=')) throw new Error('Missing tab refresh cache-buster.'); },
    fetch: async (url, options) => {
        fetchOptions = { url, options };
        return { ok: true, headers: { get: () => 'application/json' }, json: async () => ({ success: true, count: 3 }) };
    },
    confirm: () => true,
};
class HTMLFormElement { constructor() { this.dataset = {}; } }
vm.runInNewContext(source, { window, document, HTMLElement, HTMLFormElement, Date, console });
if (typeof listeners['show.bs.modal'] !== 'function' || typeof listeners['hidden.bs.modal'] !== 'function') {
    throw new Error('Modal show/hide hooks are not installed.');
}
const trigger = new HTMLElement('trigger', { credentialsCountUrl: '/plugins/credentials/ajax/Credential/TabCount?itemtype=User&items_id=2' });
listeners['show.bs.modal']({ target: modal, relatedTarget: trigger });
if (!modal.dataset.credentialsCountUrl) throw new Error('The originating tab count URL must be saved on the modal.');
(async () => {
    await listeners['hidden.bs.modal']({ target: modal });
    if (reloadCalls !== 1) throw new Error('A changed modal must refresh only the active tab.');
    if (badge.textContent !== '3') throw new Error('The tab count badge must be refreshed from the JSON endpoint.');
    if (fetchOptions?.options?.cache !== 'no-store' || fetchOptions?.options?.credentials !== 'same-origin') {
        throw new Error('The tab count must be fetched same-origin without cache.');
    }
    if (modal.dataset.credentialsChanged) throw new Error('The mutation marker must be consumed once.');
    console.log('GLPI tab refresh and live count-badge regression: OK');
})().catch((error) => { console.error(error); process.exitCode = 1; });
