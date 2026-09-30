import assert from 'node:assert/strict';
import test from 'node:test';
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

const projectRoot = process.cwd();

function readProjectFile(relativePath) {
    return readFileSync(resolve(projectRoot, relativePath), 'utf8');
}

const room = readProjectFile('resources/js/Components/Meetings/LiveKit/MeetingRoomExperience.jsx');
const stage = readProjectFile('resources/js/Components/Meetings/LiveKit/MeetingStage.jsx');
const controls = readProjectFile('resources/js/Components/Meetings/LiveKit/MeetingControlCenter.jsx');
const css = readProjectFile('resources/css/app.css');

/**
 * Splits a className string out of the source so assertions target the actual
 * utility list rendered by the component, not the whole file.
 */
function classNamesAfter(source, marker) {
    const start = source.indexOf(marker);
    assert(start >= 0, `expected to find ${marker}`);
    const end = source.indexOf('`}', start);
    const slice = source.slice(start, end > -1 ? end : start + 1200);
    return slice;
}

test('Fullscreen meeting room root is a viewport-height flex column', () => {
    const shell = classNamesAfter(room, '<div className={`meeting-room-shell');

    assert.match(shell, /meeting-room-shell/);
    assert.match(shell, /fixed/);
    assert.match(shell, /flex/);
    assert.match(shell, /min-h-0/);
    assert.match(shell, /flex-col/);
    assert.match(shell, /overflow-hidden/);
    assert.match(shell, /h-\[100dvh\]/);
    assert.match(shell, /isFullscreen \? 'inset-0 h-\[100dvh\] overflow-hidden p-0'/);
    assert.doesNotMatch(shell, /grid/);
});

test('Main body is a flex row that holds the stage and the side panel', () => {
    const body = classNamesAfter(room, '<div className={`flex min-h-0 w-full flex-1 flex-col overflow-y-auto xl:flex-row');

    assert.match(body, /min-h-0/);
    assert.match(body, /flex-1/);
    assert.match(body, /overflow-y-auto/);
    assert.match(body, /xl:flex-row/);
    assert.match(body, /xl:overflow-hidden/);
});

test('Meeting content column flexes with min-w-0/min-h-0 instead of a fixed height', () => {
    const column = classNamesAfter(room, '<section className={`meeting-room-content');

    assert.match(column, /meeting-room-content/);
    assert.match(column, /flex-1/);
    assert.match(column, /min-w-0/);
    assert.match(column, /min-h-0/);
    assert.match(column, /flex-col/);
    assert.match(column, /overflow-hidden/);
    assert.match(column, /isFullscreen \? 'min-h-0 p-3 shadow-none'/);
    assert.doesNotMatch(room, /min-h-\[42rem\]/);
});

test('Header never shrinks and the stage slot takes the freed space', () => {
    assert.match(room, /<header className="relative z-10 flex shrink-0 flex-wrap items-start/);
    assert.match(room, /<div className="relative z-0 flex min-h-0 flex-1 flex-col py-3">/);
});

test('Stage no longer uses a fixed vh/aspect-ratio box', () => {
    assert.doesNotMatch(stage, /h-\[clamp\(/);
    assert.doesNotMatch(stage, /aspect-\[/);
    assert.doesNotMatch(stage, /100vh/);
});

test('Every stage branch grows into the available space', () => {
    // Gallery, screen-share and speaker/screen focus each fill the slot.
    assert.match(stage, /<section className="h-full min-h-0 min-w-0" aria-label=\{t\('meetingRoom\.stage\.galleryView'\)\}>/);
    assert.match(stage, /<section className="flex h-full min-h-0 min-w-0 flex-col" aria-label=\{t\('meetingRoom\.stage\.sharedLayout'\)\}>/);
    assert.match(stage, /<div className="flex h-full min-h-0 min-w-0 flex-col gap-3">/);

    // The shared-content box itself grows and is allowed to shrink.
    assert.match(stage, /<div className="min-h-0 min-w-0 flex-1 overflow-hidden">\{stage\.primary \? <ScreenSharePreview/);
});

test('Screen share fills the stage with contain behavior and is not cropped', () => {
    assert.match(stage, /<VideoTrack trackRef=\{trackRef\} className="meeting-shared-content"\/>/);
    assert.match(stage, /function ScreenShareTile/);
    assert.match(stage, /<ScreenShareTile trackRef=\{stage\.primary\}\/>/);
    // The gallery and rail previously used a bare ParticipantTile for screens,
    // which fell back to LiveKit's cover/contain heuristics.
    assert.doesNotMatch(stage, /Track\.Source\.ScreenShare \? <ParticipantTile trackRef=\{track\}\/>/);

    assert.match(css, /\.edway-live-room \.meeting-shared-content/);
    assert.match(css, /object-fit: contain;/);
    assert.match(css, /object-position: center;/);
    assert.match(css, /\[data-lk-source="screen_share"\]/);
});

test('Control dock is shrink-0 and stays directly below the stage', () => {
    assert.match(controls, /meeting-control-bar relative mx-auto mt-4 flex w-full max-w-full shrink-0 flex-nowrap/);
    assert.match(css, /\.meeting-control-bar \{\n\s+flex-wrap: nowrap;\n\}/);

    // The dock is the last flow child of the meeting column, after the stage slot.
    const stageSlot = room.indexOf('<div className="relative z-0 flex min-h-0 flex-1 flex-col py-3">');
    const dock = room.indexOf('<MeetingControlCenter meeting={meeting}');
    assert(stageSlot > 0 && dock > stageSlot, 'control dock renders after the stage slot');
});

test('Desktop primary controls stay on a single row', () => {
    assert.match(controls, /flex-nowrap/);
    assert.doesNotMatch(controls, /meeting-control-bar relative mx-auto mt-4 flex w-full max-w-full shrink-0 flex-wrap/);

    // Leave is a primary control that keeps its usable size and never wraps away.
    assert.match(controls, /meeting-control-leave shrink-0/);
    assert.match(controls, /const widthClass = leave \? 'min-w-\[100px\] flex-shrink-0'/);
    assert.match(controls, /'min-w-\[92px\]'/);
});

test('Leave stays in the primary row while a side panel is open', () => {
    // The dock is inside the meeting column, not the panel, and shrinks no further.
    const columnStart = room.indexOf('<section className={`meeting-room-content');
    const columnEnd = room.indexOf('<aside className="flex h-[70vh]', columnStart);
    const column = room.slice(columnStart, columnEnd);
    assert.match(column, /<MeetingControlCenter meeting=\{meeting\}/);
    assert.doesNotMatch(column, /xl:grid-cols-/);
    // The dock inside the column is the nowrap bar, not a wrapping one.
    assert.doesNotMatch(controls, /meeting-control-bar[^"`]*flex-wrap/);

    // The panel only steals its own column; the meeting column keeps flex-1.
    assert.match(room, /<aside className="flex h-\[70vh\] min-h-\[22rem\] shrink-0 flex-col xl:h-full xl:min-h-0 xl:w-\[clamp\(17rem,24vw,22rem\)\] xl:pl-4">/);
});

test('Secondary controls collapse into More as the meeting column narrows', () => {
    // The degradation is driven by the meeting column width itself, so it also
    // applies with no panel open (previously only with --panel-open).
    assert.doesNotMatch(css, /meeting-control-bar--panel-open/);
    assert.match(css, /\.meeting-control-bar \.meeting-control-secondary/);
    assert.match(css, /\.meeting-control-bar \.meeting-control-utility/);
    assert.match(css, /\.meeting-control-bar \.meeting-more-utility/);
    assert.match(css, /\.meeting-control-bar \.meeting-more-narrow/);
    assert.match(css, /@container \(max-width: 1480px\)/);
    assert.match(css, /@container \(max-width: 1180px\)/);
    assert.match(css, /@container \(max-width: 960px\)/);

    // Every collapsed control is still reachable from the More menu.
    assert.match(controls, /className=\{`\$\{menuButton\} meeting-more-utility`\}[^<]*aria-label=\{t\(signals\.localHandRaised/);
    assert.match(controls, /className=\{`\$\{menuButton\} meeting-more-utility`\}[^<]*aria-label=\{t\('meetingRoom\.controlCenter\.openReactions'\)\}/);
    assert.match(controls, /className=\{`\$\{menuButton\} meeting-more-utility`\}[^<]*aria-label=\{t\(isFullscreen \? 'meetingRoom\.stage\.fullscreen\.exit'/);
    assert.match(controls, /meetingRoom\.controlCenter\.openHostControls/);
    assert.match(controls, /meetingRoom\.controlCenter\.openView/);
});

test('Narrow widths collapse rather than shrink every button', () => {
    assert.match(css, /@container \(max-width: 640px\)/);
    assert.match(css, /\.meeting-control-bar \{\n\s+flex-wrap: wrap;/);
    assert.match(controls, /hidden sm:block/);
    assert.doesNotMatch(css, /\.meeting-control-bar button \{[^}]*font-size: 0/);
});

test('Side panel occupies its own column without breaking stage sizing', () => {
    // The panel is a flex sibling of the flex-1 meeting column, not a grid track
    // that can squeeze the stage to an invalid size.
    assert.match(room, /xl:flex-row xl:overflow-hidden/);
    assert.match(room, /shrink-0 flex-col xl:h-full/);
    assert.doesNotMatch(room, /max-h-\[calc\(100vh-8rem\)\]/);
    assert.doesNotMatch(stage, /100vh/);
    assert.match(css, /\.meeting-room-content \{\n\s+container-type: inline-size;/);
});
