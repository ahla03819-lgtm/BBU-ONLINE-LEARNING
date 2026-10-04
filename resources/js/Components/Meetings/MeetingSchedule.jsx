import React from 'react';
import Icon from '../UI/Icon';
import {useTranslation} from '../../i18n/LocaleProvider';

/**
 * Renders a meeting's schedule.
 *
 * `available` comes from the server (Meeting::hasValidScheduledInterval). When a
 * historical row holds an inverted or partial interval, the stored timestamps are
 * NOT trustworthy, so they are withheld entirely rather than shown as a schedule
 * that ends before it starts. Actual activity may still be shown, but only under
 * its own label and never as the scheduled time.
 */
export default function MeetingSchedule({start, end, available, actualStart, actualEnd}) {
    const {t} = useTranslation();
    const format = value => value ? new Intl.DateTimeFormat(undefined, {dateStyle: 'medium', timeStyle: 'short'}).format(new Date(value)) : t('common.notSet');

    if (available === false) {
        return (
            <div className="space-y-2 text-sm text-slate-600">
                <div className="flex items-center gap-1.5 text-amber-700">
                    <Icon name="calendar" className="h-4 w-4 text-amber-500"/>
                    {t('meetings.scheduleInfo.unavailable')}
                </div>
                {(actualStart || actualEnd) && (
                    <div className="pl-5 text-xs text-slate-500">
                        <span className="font-medium">{t('meetings.scheduleInfo.actualActivity')} </span>
                        {format(actualStart)}
                        {actualEnd ? ` – ${format(actualEnd)}` : ''}
                    </div>
                )}
            </div>
        );
    }

    return (
        <div className="space-y-1 text-sm text-slate-600">
            <div className="flex items-center gap-1.5">
                <Icon name="calendar" className="h-4 w-4 text-indigo-500"/>
                {format(start)}
            </div>
            {end && (
                <div className="pl-5 text-xs text-slate-500">
                    {t('meetings.scheduleInfo.endsPrefix')} {format(end)}
                </div>
            )}
        </div>
    );
}
