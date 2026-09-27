import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import path from 'node:path';

test('CallRoom delegates to the persistent provider instead of creating its own LiveKit room', () => {
    const filePath = path.join(process.cwd(), 'resources/js/Pages/Conversations/CallRoom.jsx');
    const source = fs.readFileSync(filePath, 'utf8');

    assert.match(source, /usePersistentConversationCall/);
    assert.doesNotMatch(source, /LiveKitRoom\s*/);
    assert.match(source, /connectCall\(call, \{mode: 'full'\}\)/);
});
