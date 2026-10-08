export function isNavigationItemActive(component, itemComponent) {
    if (!component || !itemComponent) {
        return false;
    }

    if (component === itemComponent) {
        return true;
    }

    if (!itemComponent.endsWith('/Index')) {
        return false;
    }

    const section = itemComponent.slice(0, -'/Index'.length);

    return component.startsWith(`${section}/`);
}
