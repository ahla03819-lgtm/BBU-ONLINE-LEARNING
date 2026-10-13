import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';
import test from 'node:test';

const projectRoot = process.cwd();
const readProjectFile = (relativePath) => readFileSync(resolve(projectRoot, relativePath), 'utf8');
const sidebar = readProjectFile('resources/js/Components/UI/AppSidebar.jsx');
const css = readProjectFile('resources/css/app.css');
const sidebarCss = readProjectFile('resources/js/Components/UI/AppSidebar.css');

test('sidebar branding uses the official logo at the larger contained size', () => {
    assert.match(sidebar, /import bbuOfficialLogo from '..\/..\/assets\/bbu-official-logo\.png';/);
    assert.match(sidebar, /<img src=\{bbuOfficialLogo\} alt="Build Bright University" className="bbu-brand-logo"\/>/);
});

test('sidebar branding keeps the complete fixed two-line name without truncation', () => {
    const headerStart = sidebar.indexOf('<div className="bbu-brand-row">');
    const headerEnd = sidebar.indexOf('</div>', headerStart);
    const header = sidebar.slice(headerStart, headerEnd);

    assert(headerStart >= 0, 'expected the sidebar brand header');
    assert.match(header, />BBU<\/p>/);
    assert.match(header, />ONLINE LEARNING<\/p>/);
    assert.match(header, /className="bbu-brand-bbu"/);
    assert.match(header, /className="bbu-brand-online"/);
    assert.doesNotMatch(header, /truncate|text-ellipsis|overflow-hidden|min-w-0/);
});

test('sidebar branding retains its accessible desktop and mobile control targets', () => {
    assert.match(sidebar, /desktopHideButtonRef[\s\S]*?className="bbu-sidebar-toggle"[\s\S]*?aria-controls="app-sidebar"/);
    assert.match(sidebar, /closeButtonRef[\s\S]*?className="bbu-mobile-close"[\s\S]*?aria-label=/);
});

test('the existing English and Khmer font declarations remain unchanged', () => {
    assert.match(css, /html\[lang='en'\],[\s\S]*?--app-font-family: 'Times New Roman', Times, serif;/);
    assert.match(css, /html\[lang='km'\],[\s\S]*?--app-font-family: 'Khmer OS Battambang', 'Battambang', sans-serif;/);
});

test('sidebar brand text uses a fixed font-family independent of locale via CSS classes', () => {
    assert.match(sidebarCss, /\.bbu-brand-bbu\s*\{[\s\S]*?font-family: 'Times New Roman', Times, serif;/);
    assert.match(sidebarCss, /\.bbu-brand-online\s*\{[\s\S]*?font-family: 'Times New Roman', Times, serif;/);
    assert.match(sidebar, /className="bbu-brand-bbu"/);
    assert.match(sidebar, /className="bbu-brand-online"/);
    assert.doesNotMatch(sidebar, /font-sans/);
});

test('brand font-family does not depend on html lang attribute', () => {
    assert.match(sidebarCss, /html\[lang='km'\] \.bbu-brand-bbu,\s*html\[lang='km'\] \.bbu-brand-online\s*\{[\s\S]*?font-family: 'Times New Roman', Times, serif;/);
    assert.doesNotMatch(sidebar, /html\[lang/);
});

test('sidebar brand pins line-height to the English reference so locale rules cannot resize it', () => {
    assert.match(sidebarCss, /\.bbu-brand-bbu\s*\{[\s\S]*?line-height: 1\.25 !important;/);
    assert.match(sidebarCss, /\.bbu-brand-online\s*\{[\s\S]*?line-height: 1\.25 !important;/);
    assert.match(sidebarCss, /html\[lang='km'\] \.bbu-brand-bbu,\s*html\[lang='km'\] \.bbu-brand-online\s*\{[\s\S]*?line-height: 1\.25 !important;/);

    const brandParagraphs = sidebar.match(/className="bbu-brand-bbu"[\s\S]*?className="bbu-brand-online"/g) ?? [];
    assert.equal(brandParagraphs.length > 0, true, 'expected brand CSS classes present');

    // The global locale rules remain untouched: the Khmer paragraph rule still
    // exists and still governs every non-brand paragraph in the document.
    assert.match(css, /:lang\(km\) p,\s*:lang\(km\) li \{\s*line-height: 1\.8;\s*\}/);
});

test('sidebar brand uses larger typography matching reference design', () => {
    assert.match(sidebarCss, /\.bbu-brand-bbu\s*\{[\s\S]*?font-size: 1\.5rem;/);
    assert.match(sidebarCss, /\.bbu-brand-online\s*\{[\s\S]*?font-size: 0\.8125rem;/);
    assert.match(sidebarCss, /\.bbu-brand-logo\s*\{[\s\S]*?height: 3rem;/);
    assert.match(sidebarCss, /\.bbu-brand-logo\s*\{[\s\S]*?width: 2\.5rem;/);
});

test('sidebar toggle buttons have 44px minimum touch targets', () => {
    assert.match(sidebarCss, /\.bbu-sidebar-toggle\s*\{[\s\S]*?min-height: 2\.75rem;/);
    assert.match(sidebarCss, /\.bbu-sidebar-toggle\s*\{[\s\S]*?min-width: 2\.75rem;/);
    assert.match(sidebarCss, /\.bbu-mobile-close\s*\{[\s\S]*?min-height: 2\.75rem;/);
    assert.match(sidebarCss, /\.bbu-mobile-close\s*\{[\s\S]*?min-width: 2\.75rem;/);
});

test('sidebar controls retain a visible keyboard focus indicator', () => {
    assert.match(sidebarCss, /\.bbu-sidebar-toggle:focus,\s*\.bbu-mobile-close:focus\s*\{[\s\S]*?outline: 2px solid #0284c7;/);
    assert.match(sidebarCss, /outline-offset: 2px;/);
});
