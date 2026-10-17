import assert from 'node:assert/strict';
import test from 'node:test';
import {readFileSync} from 'node:fs';

const reactionSummary = readFileSync('resources/js/Components/Collaboration/Messaging/ReactionSummary.jsx', 'utf8');
const messageItem = readFileSync('resources/js/Components/Collaboration/Messaging/MessageItem.jsx', 'utf8');
const messagingPanel = readFileSync('resources/js/Components/Collaboration/Messaging/MessagingPanel.jsx', 'utf8');

test('recording cards present reactions for authorized users', () => {
    assert.match(reactionSummary, /if \(message\.hidden_at\) return null/);
});

test('ordinary message reactions report request failures and reaction controls are named', () => {
    assert.match(messagingPanel, /onReact=\{\(message, reaction\) => safely\(messaging\.setReaction\(message, reaction\)\)\}/);
    assert.match(reactionSummary, /aria-label=\{`React with \$\{emoji\}`\}/);
});

test('the authorized self-hide action is a confirmed delete affordance, not a recording action', () => {
    assert.match(messageItem, /canHide && mine && withinWindow/);
    assert.match(messageItem, /\['system', 'meeting_recording'\]\.includes\(message\.type\)/);
    assert.match(messageItem, /t\('collaboration\.deleteMessage'\)/);
    assert.match(messagingPanel, /window\.confirm\(t\('collaboration\.deleteMessageConfirm'\)\)/);
});
