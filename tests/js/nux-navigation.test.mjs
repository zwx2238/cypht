import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

function navigationContext(hash = '') {
    const display = new Map();
    const storage = new Map();
    const context = vm.createContext({
        window: { location: { hash } },
        $: (selector) => ({
            css: (_property, value) => display.set(selector, value),
            on: () => {},
        }),
        Hm_Utils: {
            get_from_local_storage: (key) => storage.get(key),
            save_to_local_storage: (key, value) => storage.set(key, value),
        },
        imap_smtp_edit_action: () => {},
        hm_init_sig_editor: () => {},
    });
    for (const file of ['modules/nux/site.js', 'modules/core/js_modules/route_handlers.js']) {
        vm.runInContext(readFileSync(new URL('../../' + file, import.meta.url), 'utf8'), context);
    }
    return { context, display, storage };
}

test('SPA account navigation expands the target section before history updates', () => {
    const { context, display } = navigationContext('#server_config_section');
    vm.runInContext("applyServersPageHandlers({}, 'quick_add_section')", context);
    assert.equal(display.get('.quick_add_section'), 'block');
    assert.equal(display.get('.server_config_section'), 'none');
    assert.equal(context.window.location.hash, '#server_config_section');
});

test('direct account navigation uses the current location when no target is supplied', () => {
    const { context, display } = navigationContext('#quick_add_section');
    vm.runInContext('expand_server_settings()', context);
    assert.equal(display.get('.quick_add_section'), 'block');
});

test('Exchange account navigation expands the Exchange server form', () => {
    const { context, display } = navigationContext('#quick_add_section');
    vm.runInContext("applyServersPageHandlers({}, 'ews_server_config_section')", context);
    assert.equal(display.get('.ews_server_config_section'), 'block');
    assert.equal(display.get('.quick_add_section'), 'none');
    assert.equal(display.get('.server_config_section'), 'none');
});

test('navigation without a section restores saved expansion instead of the previous hash', () => {
    const { context, display, storage } = navigationContext('#quick_add_section');
    storage.set('.quick_add_section', 'none');
    storage.set('.server_config_section', 'block');
    vm.runInContext("applyServersPageHandlers({}, '')", context);
    assert.equal(display.get('.quick_add_section'), 'none');
    assert.equal(display.get('.server_config_section'), 'block');
});
