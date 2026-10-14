import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';
import test from 'node:test';

const projectRoot = process.cwd();
const readProjectFile = (relativePath) => readFileSync(resolve(projectRoot, relativePath), 'utf8');
const sidebar = readProjectFile('resources/js/Components/UI/AppSidebar.jsx');
const layout = readProjectFile('resources/js/Layouts/AuthenticatedLayout.jsx');
const meetingRoom = readProjectFile('resources/js/Components/Meetings/LiveKit/MeetingRoomExperience.jsx');
const sidebarCss = readProjectFile('resources/js/Components/UI/AppSidebar.css');

test('desktop sidebar uses expanded width lg:w-72 (288px)', () => {
    assert.match(sidebar, /lg:w-72/);
    assert.doesNotMatch(sidebar, /lg:w-56/);
});

test('authenticated layout main content offset matches expanded sidebar lg:pl-72', () => {
    assert.match(layout, /lg:pl-72/);
    assert.doesNotMatch(layout, /lg:pl-56/);
});

test('meeting room experience offsets match expanded sidebar lg:left-72', () => {
    assert.match(meetingRoom, /lg:left-72/);
    assert.doesNotMatch(meetingRoom, /lg:left-56/);
});

test('sidebar brand row uses flex layout with proper gap for hamburger positioning', () => {
    assert.match(sidebarCss, /\.bbu-brand-row\s*\{[\s\S]*?display: flex;/);
    assert.match(sidebarCss, /\.bbu-brand-row\s*\{[\s\S]*?gap: 0\.5rem;/);
    assert.match(sidebarCss, /\.bbu-brand-row\s*\{[\s\S]*?align-items: center;/);
});

test('sidebar toggle button uses flex gap positioning without margin-left auto', () => {
    // Check that .bbu-sidebar-toggle block does not contain margin-left: auto
    const toggleBlock = sidebarCss.match(/\.bbu-sidebar-toggle\s*\{[\s\S]*?(?=\n\.\w|$)/)?.[0] ?? '';
    assert.doesNotMatch(toggleBlock, /margin-left: auto;/);
    assert.match(toggleBlock, /min-height: 2\.75rem;/);
    assert.match(toggleBlock, /min-width: 2\.75rem;/);
});

test('sidebar content width accommodates logo, brand text, and hamburger without overflow', () => {
    // Logo: 2.5rem (40px), brand text ~134px, hamburger 2.75rem (44px), gaps 0.5rem (8px) x2 = 16px
    // Total minimum content: 40 + 134 + 44 + 16 = 234px
    // Sidebar w-72 = 18rem = 288px, padding px-3 = 0.75rem = 12px each side = 24px
    // Available content width: 288 - 24 = 264px > 234px required
    assert.match(sidebar, /lg:w-72/);
    assert.match(sidebarCss, /\.bbu-brand-logo\s*\{[\s\S]*?width: 2\.5rem;/);
    assert.match(sidebarCss, /\.bbu-brand-text\s*\{[\s\S]*?flex-shrink: 0;/);
    assert.match(sidebarCss, /\.bbu-sidebar-toggle\s*\{[\s\S]*?min-width: 2\.75rem;/);
});

test('mobile drawer width and behavior unchanged', () => {
    assert.match(sidebar, /w-\[min\(18rem,calc\(100vw-2rem\)\)\]/);
    assert.match(sidebar, /lg:hidden/);
    assert.match(sidebarCss, /\.bbu-mobile-close\s*\{[\s\S]*?display: inline-flex;/);
    assert.match(sidebarCss, /@media \(min-width: 1024px\)\s*\{[\s\S]*?\.bbu-mobile-close\s*\{[\s\S]*?display: none;/);
});