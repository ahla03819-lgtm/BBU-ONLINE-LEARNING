import assert from 'node:assert/strict';
import test from 'node:test';
import {readFileSync} from 'node:fs';

import en from '../../resources/js/i18n/en.js';
import km from '../../resources/js/i18n/km.js';
import {createTranslator} from '../../resources/js/i18n/translate.js';

const messages = {en, km};
const translatorFor = (locale) => createTranslator({messages, locale});

const picker = readFileSync('resources/js/Components/Collaboration/Messaging/AttachmentPicker.jsx', 'utf8');

test('AttachmentPicker displays the accurate Collaboration attachment-limit wording', () => {
    assert.match(picker, /Up to 5 files, 10 MB each, 25 MB total/);
    // The 500 MB document allowance belongs to the Conversations workflow, not Collaboration.
    assert.ok(! /500 MB/.test(picker));
    // Functional limits are unchanged: 5 files, 10 MB per file, 25 MB combined cap.
    assert.match(picker, /next\.length >= 5/);
    assert.match(picker, /25 \* 1024 \* 1024/);
    assert.match(picker, /10 \* 1024 \* 1024/);
});

test('the English attachFilesHint catalogue string matches the rendered hint', () => {
    assert.equal(
        en.collaboration.attachFilesHint,
        'Up to 5 files, 10 MB each, 25 MB total',
    );
});

test('Khmer falls back to the English hint instead of rendering nothing', () => {
    const t = translatorFor('km');
    const resolved = t('collaboration.attachFilesHint');
    assert.equal(resolved, en.collaboration.attachFilesHint);
    assert.ok(resolved.length > 0);
});

test('the English documentsUpTo500Mb hint is still present and unchanged', () => {
    assert.equal(en.collaboration.documentsUpTo500Mb, 'Documents can be up to 500 MB.');
});

test('the Khmer catalogue never introduces keys English does not define', () => {
    const walk = (source, prefix = '') => Object.entries(source).flatMap(([key, value]) => {
        const path = prefix ? `${prefix}.${key}` : key;
        return value && typeof value === 'object' ? walk(value, path) : [path];
    });
    const extra = walk(km).filter((path) => {
        if (path === 'collaboration.attachFilesHint') {
            return false; // intentionally absent; resolved by fallback.
        }
        let current = en;
        for (const segment of path.split('.')) {
            current = current?.[segment];
        }
        return current === undefined;
    });
    assert.deepEqual(extra, []);
});

test('the Conversations ChatComposer advertises the real 500 MB document allowance', () => {
    const composer = readFileSync('resources/js/Components/Conversations/ChatComposer.jsx', 'utf8');
    assert.match(composer, /500 \* 1024 \* 1024/);
    assert.match(composer, /Documents can be up to 500 MB\./);
});