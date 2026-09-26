import { useEffect, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

function monthRange() {
    const now = new Date();

    return {
        start: new Date(now.getFullYear(), now.getMonth(), 1).toISOString(),
        end: new Date(now.getFullYear(), now.getMonth() + 1, 1).toISOString(),
    };
}

function dayKey(value, timezone) {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date(value));
}

function eventTime(value, timezone) {
    return new Intl.DateTimeFormat(undefined, {
        timeZone: timezone,
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

export default function Calendar() {
    const [events, setEvents] = useState({});
    const [timezone, setTimezone] = useState('UTC');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        const controller = new AbortController();

        const fetchEvents = async () => {
            try {
                const params = new URLSearchParams({ view: 'month', ...monthRange() });
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
                const responseTimezone = data.timezone || 'UTC';
                const grouped = (data.events || []).reduce((days, event) => {
                    if (!event.starts_at) return days;

                    const day = dayKey(event.starts_at, responseTimezone);
                    days[day] ??= { meetings: [], assignments: [] };
                    days[day][event.type === 'meeting' ? 'meetings' : 'assignments'].push(event);

                    return days;
                }, {});

                setTimezone(responseTimezone);
                setEvents(grouped);
                setError('');
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
    }, []);

    const days = Object.entries(events).sort(([left], [right]) => left.localeCompare(right));

    return (
        <AuthenticatedLayout>
            <div className="p-6">
                <h1 className="mb-4 text-2xl font-semibold">Calendar</h1>

                {loading && <p className="py-8 text-center text-gray-500">Loading calendar events...</p>}

                {error && (
                    <div className="mb-4 rounded border-l-4 border-red-500 bg-red-100 p-4 text-red-700" role="alert">
                        <p className="font-medium">Error</p>
                        <p>{error}</p>
                    </div>
                )}

                {!loading && !error && days.length === 0 && (
                    <p className="py-8 text-center text-gray-500">No events scheduled for this month</p>
                )}

                {!loading && !error && days.length > 0 && (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {days.map(([date, dayEvents]) => (
                            <section key={date} className="rounded-lg border p-3">
                                <h2 className="mb-2 font-medium">
                                    {new Intl.DateTimeFormat(undefined, { dateStyle: 'long', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`))}
                                </h2>

                                {[...dayEvents.meetings, ...dayEvents.assignments].map((event) => (
                                    <a
                                        key={event.id}
                                        href={event.url}
                                        className={`mb-2 block rounded p-2 ${event.type === 'meeting' ? 'bg-indigo-50 text-indigo-700' : 'bg-blue-50 text-blue-700'}`}
                                    >
                                        <span className="block text-sm font-medium">{event.title}</span>
                                        <span className="block text-xs opacity-80">{eventTime(event.starts_at, timezone)}</span>
                                    </a>
                                ))}
                            </section>
                        ))}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
