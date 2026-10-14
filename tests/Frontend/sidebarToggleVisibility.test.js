import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';
import test from 'node:test';

const projectRoot = process.cwd();
const readProjectFile = (relativePath) => readFileSync(resolve(projectRoot, relativePath), 'utf8');
const sidebar = readProjectFile('resources/js/Components/UI/AppSidebar.jsx');
const topbar = readProjectFile('resources/js/Components/UI/AppTopbar.jsx');
const layout = readProjectFile('resources/js/Layouts/AuthenticatedLayout.jsx');
const sidebarCss = readProjectFile('resources/js/Components/UI/AppSidebar.css');

test('desktop sidebar toggles have mutually exclusive ownership across expanded and collapsed states', () => {
    assert.match(sidebar, /\{desktopOpen && <button ref=\{desktopHideButtonRef\}[\s\S]*?className="bbu-sidebar-toggle"[\s\S]*?aria-controls="app-sidebar"[\s\S]*?aria-expanded=\{desktopOpen\}/);
    assert.match(topbar, /\{!sidebarExpanded && <button ref=\{desktopShowSidebarButtonRef\}[\s\S]*?aria-controls="app-sidebar"[\s\S]*?aria-expanded=\{sidebarExpanded\}/);
    assert.match(layout, /desktopOpen=\{desktopSidebarOpen\}/);
    assert.match(layout, /sidebarExpanded=\{desktopSidebarOpen\}/);
});

test('all sidebar open and close controls retain accessible targets and focus behavior', () => {
    assert.match(topbar, /menuButtonRef[\s\S]*?aria-controls="app-sidebar"[\s\S]*?aria-expanded=\{navigationOpen\}[\s\S]*?lg:hidden/);
    assert.match(topbar, /desktopShowSidebarButtonRef[\s\S]*?min-h-11 min-w-11 items-center justify-center[\s\S]*?lg:inline-flex/);
    assert.match(sidebarCss, /\.bbu-sidebar-toggle\s*\{[\s\S]*?min-height: 2\.75rem;[\s\S]*?min-width: 2\.75rem;/);
    assert.match(sidebarCss, /\.bbu-mobile-close\s*\{[\s\S]*?min-height: 2\.75rem;[\s\S]*?min-width: 2\.75rem;/);
    assert.match(layout, /pendingDesktopSidebarFocusRef\.current = 'show';[\s\S]*?desktopShowSidebarButtonRef\.current/);
    assert.match(layout, /pendingDesktopSidebarFocusRef\.current = 'hide';[\s\S]*?desktopHideSidebarButtonRef\.current/);
});
