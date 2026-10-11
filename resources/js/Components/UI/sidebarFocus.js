export function focusVisibleElement(element) {
    if (!element
        || element.hasAttribute('disabled')
        || element.closest('[aria-hidden="true"], [inert]')
        || element.getClientRects().length === 0) {
        return false;
    }

    element.focus({preventScroll: true});

    return document.activeElement === element;
}

export function moveFocusOutOfContainer(container, fallback) {
    if (!container?.contains(document.activeElement)) {
        return false;
    }

    return focusVisibleElement(fallback);
}
