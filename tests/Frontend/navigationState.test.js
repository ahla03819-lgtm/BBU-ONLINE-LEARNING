import test from 'node:test';
import assert from 'node:assert/strict';
import {isNavigationItemActive} from '../../resources/js/Components/UI/navigationState.js';

test('navigation highlights the exact Inertia page', () => {
    assert.equal(isNavigationItemActive('Dashboard', 'Dashboard'), true);
    assert.equal(isNavigationItemActive('Calendar/Index', 'Calendar/Index'), true);
});

test('index navigation remains highlighted on child pages', () => {
    assert.equal(isNavigationItemActive('Classes/Show', 'Classes/Index'), true);
    assert.equal(isNavigationItemActive('Collaboration/Workspace', 'Collaboration/Index'), true);
});

test('navigation does not highlight unrelated or non-index sections', () => {
    assert.equal(isNavigationItemActive('Results/MyResults', 'Results/Index'), true);
    assert.equal(isNavigationItemActive('Classes/Show', 'Dashboard'), false);
    assert.equal(isNavigationItemActive('Meetings/Room', 'Meetings/Show'), false);
    assert.equal(isNavigationItemActive('', 'Classes/Index'), false);
});
