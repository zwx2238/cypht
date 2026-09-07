import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../modules/core/site.js', import.meta.url), 'utf8');
const providerFunction = source.slice(source.indexOf('function getEmailProviderKey('), source.indexOf('function setupActionSchedule('));
const context = vm.createContext({});
vm.runInContext(providerFunction, context);

test('QQ, Foxmail and 163 addresses select their corresponding IMAP/SMTP presets', () => {
    for (const [email, expected] of [
        ['123@qq.com', 'qq'], ['name@foxmail.com', 'qq'], ['name@163.com', '163'],
        ['  name@QQ.COM  ', 'qq'], ['name@163.COM', '163'], ['name@gmail.com', 'gmail'],
    ]) {
        assert.equal(context.getEmailProviderKey(email), expected);
    }
});

test('incomplete and unrelated domains are not mistaken for known providers', () => {
    for (const email of ['name@com', 'name@qq', 'name@63.com', 'name@qq.com.example.test', 'name@', 'name', 'a@b@c']) {
        assert.equal(context.getEmailProviderKey(email), '');
    }
});
