import React, {createContext, useContext, useState} from 'react';

const FullscreenContext = createContext(null);

export function FullscreenProvider({children}) {
    const [isFullscreen, setIsFullscreen] = useState(false);
    const [isMeetingRoom, setIsMeetingRoom] = useState(false);

    return (
        <FullscreenContext.Provider value={{isFullscreen, setIsFullscreen, isMeetingRoom, setIsMeetingRoom}}>
            {children}
        </FullscreenContext.Provider>
    );
}

export function useFullscreenContext() {
    const context = useContext(FullscreenContext);
    if (!context) {
        throw new Error('useFullscreenContext must be used within a FullscreenProvider');
    }
    return context;
}