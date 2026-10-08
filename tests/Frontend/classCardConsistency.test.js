import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';
import test from 'node:test';

const projectRoot = process.cwd();
const readProjectFile = (relativePath) => readFileSync(resolve(projectRoot, relativePath), 'utf8');

const classCard = readProjectFile('resources/js/Components/Classes/ClassCard.jsx');
const coverPanel = readProjectFile('resources/js/Components/Classes/EditClassCoverPanel.jsx');
const classesIndex = readProjectFile('resources/js/Pages/Classes/Index.jsx');
const collaborationIndex = readProjectFile('resources/js/Pages/Collaboration/Index.jsx');

test('Classes and Collaboration render the shared ClassCard in matching responsive grids', () => {
    for (const page of [classesIndex, collaborationIndex]) {
        assert.match(page, /import ClassCard, \{classCardTones\}/);
        assert.match(page, /grid gap-4 sm:grid-cols-2 xl:grid-cols-4/);
        assert.match(page, /<ClassCard/);
    }

    assert.doesNotMatch(classesIndex, /<div key=\{schoolClass\.id\}><ClassCard/);
    assert.doesNotMatch(collaborationIndex, /EditClassCoverPanel|coverActions=/);
});

test('ClassCard owns the shared card dimensions, cover, hover, and footer presentation', () => {
    assert.match(classCard, /<article className="group flex h-full flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition duration-200/);
    assert.match(classCard, /hover:-translate-y-0\.5 hover:border-sky-200 hover:shadow-lg hover:shadow-sky-100\/70/);
    assert.match(classCard, /relative min-h-28 overflow-hidden bg-gradient-to-br p-5 text-white/);
    assert.match(classCard, /absolute inset-0 h-full w-full object-cover/);
    assert.match(classCard, /flex flex-1 flex-col p-5/);
    assert.match(classCard, /<div className="mt-auto pt-5">/);
    assert.match(classCard, /flex items-center justify-between border-t border-slate-100 pt-3/);
});

test('Cover actions are a sibling of the navigation link and only render for authorized classes', () => {
    const linkEnd = classCard.indexOf('</Link>');
    const actions = classCard.indexOf('{coverActions &&', linkEnd);

    assert(linkEnd >= 0 && actions > linkEnd, 'cover actions must render after the navigation link closes');
    assert.match(classesIndex, /coverActions=\{schoolClass\.canManageCover \? <EditClassCoverPanel schoolClass=\{schoolClass\}\/\> : null\}/);
    assert.match(classCard, /\{coverActions && <div className="border-t border-slate-100">\{coverActions\}<\/div>\}/);
});

test('Upload and removal controls preserve form behavior without triggering card navigation', () => {
    assert.match(coverPanel, /event\.preventDefault\(\)/);
    assert.match(coverPanel, /form\.post\(`\/classes\/\$\{schoolClass\.id\}\/cover`/);
    assert.match(coverPanel, /type="file"/);
    assert.match(coverPanel, /type="button"/);
    assert.match(coverPanel, /form\.delete\(`\/classes\/\$\{schoolClass\.id\}\/cover`/);
});

test('Card navigation and cover controls expose visible keyboard focus', () => {
    assert.match(classCard, /focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-sky-600/);
    assert.match(coverPanel, /<input className="[^"]*focus-visible:[^"]*" type="file"/);
    assert.match(coverPanel, /<button className="[^"]*focus-visible:ring-2/);
    assert.match(coverPanel, /<button type="button" className="[^"]*focus-visible:ring-2/);
    assert.match(coverPanel, /role="alert"/);
});

test('Fallback covers and card motion respect existing behavior and reduced-motion preferences', () => {
    assert.match(classCard, /schoolClass\.coverImageUrl && <img/);
    assert.match(classCard, /event\.currentTarget\.hidden = true/);
    assert.match(classCard, /bg-gradient-to-br/);
    assert.match(classCard, /motion-reduce:transform-none motion-reduce:transition-none/);
});

test('Collaboration keeps its page-specific destination, copy, and channel count', () => {
    assert.match(collaborationIndex, /href=\{`\/collaboration\/classes\/\$\{schoolClass\.id\}`\}/);
    assert.match(collaborationIndex, /actionLabel="Open collaboration"/);
    assert.match(collaborationIndex, /secondary="Collaboration workspace"/);
    assert.match(collaborationIndex, /count=\{schoolClass\.channels_count\}/);
});
