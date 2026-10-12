import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';
import test from 'node:test';

const projectRoot = process.cwd();
const readProjectFile = (relativePath) => readFileSync(resolve(projectRoot, relativePath), 'utf8');
const sidebar = readProjectFile('resources/js/Components/UI/AppSidebar.jsx');
const css = readProjectFile('resources/css/app.css');

test('sidebar branding uses the official logo at the modestly larger contained size', () => {
    assert.match(sidebar, /import bbuOfficialLogo from '..\/..\/assets\/bbu-official-logo\.png';/);
    assert.match(sidebar, /<img src=\{bbuOfficialLogo\} alt="Build Bright University" className="h-12 w-10 shrink-0 object-contain"\/>/);
});

test('sidebar branding keeps the complete fixed two-line name without truncation', () => {
    const headerStart = sidebar.indexOf('<div className="flex items-center gap-1.5 px-1">');
    const headerEnd = sidebar.indexOf('</div>', headerStart);
    const header = sidebar.slice(headerStart, headerEnd);

    assert(headerStart >= 0, 'expected the sidebar brand header');
    assert.match(header, />BBU<\/p>/);
    assert.match(header, />ONLINE LEARNING<\/p>/);
    assert.match(header, /className="whitespace-nowrap font-bold tracking-tight text-slate-900"/);
    assert.match(header, /className="whitespace-nowrap text-\[10px\] font-semibold tracking-wide text-slate-500"/);
    assert.doesNotMatch(header, /truncate|text-ellipsis|overflow-hidden|min-w-0/);
});

test('sidebar branding retains its accessible desktop and mobile control targets', () => {
    assert.match(sidebar, /desktopHideButtonRef[\s\S]*?hidden min-h-11 min-w-11[\s\S]*?lg:inline-flex/);
    assert.match(sidebar, /closeButtonRef[\s\S]*?inline-flex min-h-11 min-w-11[\s\S]*?lg:hidden/);
});

test('the existing English and Khmer font declarations remain unchanged', () => {
    assert.match(css, /html\[lang='en'\],[\s\S]*?--app-font-family: 'Times New Roman', Times, serif;/);
    assert.match(css, /html\[lang='km'\],[\s\S]*?--app-font-family: 'Khmer OS Battambang', 'Battambang', sans-serif;/);
});
