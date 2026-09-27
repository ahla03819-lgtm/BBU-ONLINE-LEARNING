export function shouldStartMiniWindowDrag({button, isPrimary, targetIsInteractive}) {
    return button === 0 && isPrimary && !targetIsInteractive;
}

export function clampMiniWindowPosition(position, size, viewport) {
    const maxLeft = Math.max(0, viewport.width - size.width);
    const maxTop = Math.max(0, viewport.height - size.height);

    return {
        left: Math.min(Math.max(0, position.left), maxLeft),
        top: Math.min(Math.max(0, position.top), maxTop),
    };
}

export function dragMiniWindowPosition({origin, pointerStart, pointer, size, viewport}) {
    return clampMiniWindowPosition({
        left: origin.left + pointer.x - pointerStart.x,
        top: origin.top + pointer.y - pointerStart.y,
    }, size, viewport);
}