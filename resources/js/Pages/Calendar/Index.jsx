import { useEffect, useMemo, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MAX_VISIBLE_EVENTS = 3;

function pad(value) {
    return String(value).padStart(2, '0');
}

/**
 * Calendar date values (year, 1-12 month, day) for "now" in the academic
 * timezone, never in the browser timezone.
 */
function todayIn(timezone) {
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone: timezone,
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
    }).formatToParts(new Date());
    const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));

    return { year: Number(values.year), month: Number(values.month), day: Number(values.day) };
}

function addMonths(viewedMonth, offset) {
    const index = viewedMonth.year * 12 + (viewedMonth.month - 1) + offset;

    return {
        year: Math.floor(index / 12),
        month: ((index % 12) + 12) % 12 + 1,
    };
}

/**
 * UTC Date used purely as neutral calendar arithmetic. Only getUTC* accessors
 * are ever used on these objects so the browser timezone cannot leak in.
 */
function utcDate(year, month, day) {
    return new Date(Date.UTC(year, month - 1, day));
}

function utcDateKey(date) {
    return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`;
}

/**
 * Month query boundaries are DATE-ONLY values; the server resolves their
 * midnight in the academic timezone, so no browser-local instant is sent.
 */
function monthRange(viewedMonth) {
    const start = utcDate(viewedMonth.year, viewedMonth.month, 1);
    const end = addMonths(viewedMonth, 1);

    return {
        start: utcDateKey(start),
        end: utcDateKey(utcDate(end.year, end.month, 1)),
    };
}

function calendarDays(viewedMonth) {
    const firstWeekday = utcDate(viewedMonth.year, viewedMonth.month, 1).getUTCDay();
    const daysInMonth = utcDate(viewedMonth.year, viewedMonth.month + 1, 0).getUTCDate();
    const cellCount = Math.ceil((firstWeekday + daysInMonth) / 7) * 7;

    return Array.from({ length: cellCount }, (_, index) => {
        const date = utcDate(viewedMonth.year, viewedMonth.month, index - firstWeekday + 1);

        return {
            key: utcDateKey(date),
            year: date.getUTCFullYear(),
            month: date.getUTCMonth() + 1,
            day: date.getUTCDate(),
            date,
        };
    });
}

function eventDayKey(value, timezone) {
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(new Date(value));
    const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));

    return `${values.year}-${values.month}-${values.day}`;
}

function eventTime(value, timezone) {
    return new Intl.DateTimeFormat(undefined, {
        timeZone: timezone,
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

export default function Calendar({ timezone = 'UTC' }) {
    const [viewedMonth, setViewedMonth] = useState(() => {
        const today = todayIn(timezone);

        return { year: today.year, month: today.month };
    });
    const [events, setEvents] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        const controller = new AbortController();

        const fetchEvents = async () => {
            setLoading(true);
            setError('');
            setEvents([]);

            try {
                const params = new URLSearchParams({ view: 'month', ...monthRange(viewedMonth) });
                const response = await fetch(`/calendar/events?${params}`, {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: controller.signal,
                });

                if (!response.ok) {
                    throw new Error('Failed to load calendar events');
                }

                const data = await response.json();
                setEvents(Array.isArray(data.events) ? data.events : []);
            } catch (fetchError) {
                if (fetchError.name !== 'AbortError') {
                    setError(fetchError.message || 'Failed to load calendar events');
                }
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        };

        fetchEvents();

        return () => controller.abort();
    }, [viewedMonth, timezone]);

    const days = useMemo(() => calendarDays(viewedMonth), [viewedMonth]);
    const eventsByDay = useMemo(() => events.reduce((grouped, event) => {
        if (!event.starts_at) return grouped;

        const key = eventDayKey(event.starts_at, timezone);
        grouped[key] ??= [];
        grouped[key].push(event);

        return grouped;
    }, {}), [events, timezone]);
    const todayKey = (() => {
        const today = todayIn(timezone);

        return `${today.year}-${pad(today.month)}-${pad(today.day)}`;
    })();
    const monthTitle = new Intl.DateTimeFormat(undefined, {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(utcDate(viewedMonth.year, viewedMonth.month, 1));

    const moveMonth = (offset) => {
        setViewedMonth((current) => addMonths(current, offset));
    };

    const goToToday = () => {
        const today = todayIn(timezone);

        setViewedMonth({ year: today.year, month: today.month });
    };

    return (
        <AuthenticatedLayout>
            <div className="space-y-4">
                <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="text-sm font-medium text-sky-700">Calendar</p>
                        <h1 className="text-2xl font-semibold text-slate-900">{monthTitle}</h1>
                    </div>

                    <div className="flex items-center gap-2" aria-label="Calendar month navigation">
                        <button
                            type="button"
                            onClick={() => moveMonth(-1)}
                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-sky-500"
                        >
                            Previous
                        </button>
                        <button
                            type="button"
                            onClick={goToToday}
                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-sky-500"
                        >
                            Today
                        </button>
                        <button
                            type="button"
                            onClick={() => moveMonth(1)}
                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-sky-500"
                        >
                            Next
                        </button>
                    </div>
                </header>

                {loading && (
                    <p className="rounded-lg bg-sky-50 px-4 py-3 text-sm text-sky-800" role="status">
                        Loading calendar events...
                    </p>
                )}

                {error && (
                    <div className="rounded-lg border-l-4 border-red-500 bg-red-50 p-4 text-red-800" role="alert">
                        <p className="font-medium">Calendar events could not be loaded</p>
                        <p className="text-sm">{error}</p>
                    </div>
                )}

                {!loading && !error && events.length === 0 && (
                    <p className="text-sm text-slate-500">There are no events scheduled for this month.</p>
                )}

                <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div className="min-w-[700px]">
                        <div className="grid grid-cols-7 border-b border-slate-200 bg-slate-50">
                            {WEEKDAYS.map((weekday) => (
                                <div key={weekday} className="px-2 py-3 text-center text-xs font-semibold uppercase tracking-wide text-slate-600">
                                    {weekday}
                                </div>
                            ))}
                        </div>

                        <div className="grid grid-cols-7">
                            {days.map((day) => {
                                const dayEvents = eventsByDay[day.key] || [];
                                const visibleEvents = dayEvents.slice(0, MAX_VISIBLE_EVENTS);
                                const hiddenCount = dayEvents.length - visibleEvents.length;
                                const outsideMonth = day.month !== viewedMonth.month;
                                const isToday = day.key === todayKey;

                                return (
                                    <section
                                        key={day.key}
                                        className={`min-h-32 border-b border-r border-slate-200 p-2 last:border-r-0 sm:min-h-36 ${outsideMonth ? 'bg-slate-50 text-slate-400' : 'bg-white text-slate-800'}`}
                                        aria-label={new Intl.DateTimeFormat(undefined, { dateStyle: 'full', timeZone: 'UTC' }).format(day.date)}
                                    >
                                        <time
                                            dateTime={day.key}
                                            aria-current={isToday ? 'date' : undefined}
                                            className={`inline-flex h-7 w-7 items-center justify-center rounded-full text-sm font-semibold ${isToday ? 'bg-sky-700 text-white ring-2 ring-sky-200' : ''}`}
                                        >
                                            {day.day}
                                        </time>

                                        <div className="mt-2 space-y-1">
                                            {visibleEvents.map((event) => {
                                                const isMeeting = event.type === 'meeting';

                                                return (
                                                    <a
                                                        key={event.id}
                                                        href={event.url}
                                                        className={`block rounded-md border-l-4 px-2 py-1.5 text-xs transition hover:brightness-95 focus:outline-none focus:ring-2 focus:ring-sky-500 ${isMeeting ? 'border-indigo-500 bg-indigo-50 text-indigo-900' : 'border-amber-500 bg-amber-50 text-amber-950'}`}
                                                    >
                                                        <span className="block truncate font-semibold">{event.title}</span>
                                                        <span className="block text-[11px] opacity-80">
                                                            {isMeeting ? 'Meeting' : 'Due'} · {eventTime(event.starts_at, timezone)}
                                                        </span>
                                                    </a>
                                                );
                                            })}

                                            {hiddenCount > 0 && (
                                                <p className="px-2 py-1 text-xs font-medium text-slate-600">
                                                    +{hiddenCount} more
                                                </p>
                                            )}
                                        </div>
                                    </section>
                                );
                            })}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
