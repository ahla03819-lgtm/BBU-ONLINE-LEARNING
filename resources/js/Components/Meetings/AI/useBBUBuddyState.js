/**
 * BBU Buddy state machine.
 *
 * The assistant lifecycle is intentionally a small, deterministic state machine.
 * Every transition is driven by an explicit action; nothing mutates the state
 * as a side effect of a timer or an unowned event, so the UI can render a single
 * source of truth and the tests can walk the same graph.
 *
 * States: idle | listening | transcribing | translating | thinking | taking_notes
 *         | success | warning | sleeping
 *
 * The machine core (`createBuddyMachine`) is deliberately dependency-free so it
 * can be unit-tested in plain Node without a React render. The React hook
 * (`useBBUBuddyState`) is a thin wrapper that keeps the same shape in a
 * component.
 */
import {useCallback, useRef, useState} from 'react';

export const BUDDY_STATES = Object.freeze([
    'idle',
    'listening',
    'transcribing',
    'translating',
    'thinking',
    'taking_notes',
    'success',
    'warning',
    'sleeping',
]);

const STATE_SET = new Set(BUDDY_STATES);

export const isBuddyState = (value) => STATE_SET.has(value);

/**
 * Validate that a transition is allowed. The state machine is permissive by
 * design: the assistant may move from any active state back to idle or sleeping,
 * and it may chain through the processing states in the documented order. The
 * only hard rule is that the target must be a real state and different from the
 * current one.
 */
export function isValidBuddyTransition(from, to) {
    return isBuddyState(from) && isBuddyState(to) && from !== to;
}

/**
 * Create a self-contained buddy state machine. No React dependency, so the same
 * logic can be exercised from a plain Node test or from inside a component.
 *
 * @param {{initialState?: string, onStateChange?: (state: string, prev: string) => void}} options
 */
export function createBuddyMachine({initialState = 'idle', onStateChange} = {}) {
    if (!isBuddyState(initialState)) {
        throw new Error(`createBuddyMachine: unknown initial state "${initialState}"`);
    }

    let current = initialState;
    const listeners = new Set();

    const notify = (next, prev) => {
        if (onStateChange) onStateChange(next, prev);
        for (const listener of listeners) {
            try { listener(next, prev); } catch (error) { console.error(error); }
        }
    };

    const set = (next) => {
        if (!isBuddyState(next)) {
            throw new Error(`buddy machine: unknown state "${next}"`);
        }
        const prev = current;
        if (prev === next) return;
        current = next;
        notify(next, prev);
    };

    const transition = (next) => {
        if (!isValidBuddyTransition(current, next)) {
            throw new Error(`buddy machine: invalid transition "${current}" -> "${next}"`);
        }
        set(next);
    };

    const subscribe = (listener) => {
        listeners.add(listener);
        return () => { listeners.delete(listener); };
    };

    const reset = () => { set('idle'); };

    return {
        get state() { return current; },
        STATES: BUDDY_STATES,
        set,
        transition,
        subscribe,
        reset,
        isState: isBuddyState,
        isValidTransition: isValidBuddyTransition,
    };
}

/**
 * React-bound version of the same machine. The hook returns the same shape as
 * `createBuddyMachine` so callers do not need to know which flavour they have.
 *
 * @param {{initialState?: string, onStateChange?: (state: string, prev: string) => void}} options
 */
export function useBBUBuddyState({initialState = 'idle', onStateChange} = {}) {
    if (!isBuddyState(initialState)) {
        throw new Error(`useBBUBuddyState: unknown initial state "${initialState}"`);
    }

    const [state, setState] = useState(initialState);
    const prevRef = useRef(initialState);
    const listenersRef = useRef(new Set());

    const set = useCallback((next) => {
        if (!isBuddyState(next)) {
            throw new Error(`useBBUBuddyState: unknown state "${next}"`);
        }
        const prev = prevRef.current;
        if (prev === next) return;
        prevRef.current = next;
        setState(next);
        if (onStateChange) onStateChange(next, prev);
        for (const listener of listenersRef.current) {
            try { listener(next, prev); } catch (error) { console.error(error); }
        }
    }, [onStateChange]);

    const transition = useCallback((next) => {
        if (!isValidBuddyTransition(prevRef.current, next)) {
            throw new Error(`useBBUBuddyState: invalid transition "${prevRef.current}" -> "${next}"`);
        }
        set(next);
    }, [set]);

    const subscribe = useCallback((listener) => {
        listenersRef.current.add(listener);
        return () => { listenersRef.current.delete(listener); };
    }, []);

    const reset = useCallback(() => { set('idle'); }, [set]);

    return {
        state,
        STATES: BUDDY_STATES,
        set,
        transition,
        subscribe,
        reset,
        isState: isBuddyState,
        isValidTransition: isValidBuddyTransition,
    };
}

export default useBBUBuddyState;