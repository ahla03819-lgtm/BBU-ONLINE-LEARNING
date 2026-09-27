import React from 'react';
import Lobby from './Lobby';

export default function Room(props) {
    return <Lobby {...props} resumeSession />;
}
