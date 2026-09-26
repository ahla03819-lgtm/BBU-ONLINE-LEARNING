import React from 'react';
import {Head, Link} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import Icon from '../../Components/UI/Icon';
import SectionCard from '../../Components/UI/SectionCard';
import MeetingPageHeader from '../../Components/Meetings/MeetingPageHeader';
import MeetingStatusBadge from '../../Components/Meetings/MeetingStatusBadge';

export default function Attendance({schoolClass, meeting, rows, summary, export_url: exportUrl}) {
    const base = `/school-classes/${schoolClass.id}/meetings/${meeting.uuid}`;

    return <Layout>
        <Head title={`${meeting.title} attendance`}/>
        <MeetingPageHeader
            schoolClass={schoolClass}
            meeting={meeting}
            eyebrow="Meeting attendance"
            title={meeting.title}
            description="Attendance recorded for this single meeting occurrence, derived from the live session the class actually joined."
            backHref={base}
            backLabel="Meeting details"
            action={<a className="btn-secondary" href={exportUrl}><Icon name="download" className="h-4 w-4"/>Export CSV</a>}
        />
        <div className="mt-6 space-y-6">
            <SectionCard className="p-6" icon="calendar" tone="blue" title="Meeting summary" description="Context for this occurrence and how its duration was determined.">
                <div className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Info label="Class" value={schoolClass.section ? `${schoolClass.name} ${schoolClass.section}` : schoolClass.name}/>
                    <Info label="Status" value={<MeetingStatusBadge status={meeting.status}/>}/>
                    <Info label="Occurrence" value={`${formatDate(meeting.scheduled_start_at, meeting.occurrence_timezone)}${meeting.scheduled_end_at ? ` – ${formatTime(meeting.scheduled_end_at, meeting.occurrence_timezone)}` : ''}`}/>
                    <Info label="Meeting duration" value={meeting.duration_is_authoritative ? formatDuration(meeting.duration_seconds) : 'Legacy / unavailable'}/>
                </div>
                {meeting.is_cancelled && <p className="mt-5 flex items-start gap-2 rounded-xl border border-rose-100 bg-rose-50 p-4 text-sm leading-6 text-rose-800"><Icon name="x" className="mt-0.5 h-4 w-4 shrink-0"/>This meeting was cancelled, so attendance is not applicable for this occurrence.</p>}
                {!meeting.is_cancelled && !meeting.duration_is_authoritative && <p className="mt-5 flex items-start gap-2 rounded-xl border border-amber-100 bg-amber-50 p-4 text-sm leading-6 text-amber-800"><Icon name="clock" className="mt-0.5 h-4 w-4 shrink-0"/>No live session was recorded for this meeting, so attendance percentages are unavailable.</p>}
            </SectionCard>

            <SectionCard className="p-5" icon="users" tone="lavender" title="Attendance roster" description={`${summary.roster_count} enrolled · ${summary.attended_count} attended · ${summary.absent_count} absent${summary.not_applicable_count > 0 ? ` · ${summary.not_applicable_count} not applicable` : ''}`}>
                {rows.length === 0
                    ? <p className="rounded-xl border border-dashed border-indigo-100 bg-indigo-50/35 p-5 text-sm text-slate-500">No students were enrolled for this meeting occurrence.</p>
                    : <div className="mt-4 -mx-2 overflow-x-auto px-2">
                        <table className="w-full min-w-[64rem] border-collapse text-left text-sm">
                            <thead>
                                <tr className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                                    <th className="px-4 py-3 font-semibold" scope="col">Student Number</th>
                                    <th className="px-4 py-3 font-semibold" scope="col">Student Name</th>
                                    <th className="px-4 py-3 font-semibold" scope="col">Status</th>
                                    <th className="px-4 py-3 font-semibold" scope="col">First Joined</th>
                                    <th className="px-4 py-3 font-semibold" scope="col">Last Left</th>
                                    <th className="px-4 py-3 text-right font-semibold" scope="col">Sessions</th>
                                    <th className="px-4 py-3 text-right font-semibold" scope="col">Attended Duration</th>
                                    <th className="px-4 py-3 text-right font-semibold" scope="col">Attendance %</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row, index) => <tr className="border-b border-slate-100 align-top transition hover:bg-indigo-50/20" key={`${row.student_number || 'student'}-${index}`}>
                                    <td className="px-4 py-4 text-slate-600">{row.student_number || '—'}</td>
                                    <td className="px-4 py-4 font-semibold text-slate-900">{row.student_name || 'Unnamed student'}</td>
                                    <td className="px-4 py-4"><StatusBadge status={row.status}/></td>
                                    <td className="px-4 py-4 text-slate-600">{formatDate(row.first_joined_at, meeting.occurrence_timezone)}</td>
                                    <td className="px-4 py-4 text-slate-600">{formatDate(row.last_left_at, meeting.occurrence_timezone)}</td>
                                    <td className="px-4 py-4 text-right tabular-nums text-slate-700">{row.sessions_count}</td>
                                    <td className="px-4 py-4 text-right tabular-nums text-slate-700">{formatDuration(row.attended_seconds)}</td>
                                    <td className="px-4 py-4 text-right tabular-nums font-semibold text-slate-800">{row.attendance_percentage === null || row.attendance_percentage === undefined ? 'N/A' : `${row.attendance_percentage}%`}</td>
                                </tr>)}
                            </tbody>
                        </table>
                    </div>}
            </SectionCard>
        </div>
    </Layout>;
}

function Info({label, value}) {
    return <div className="rounded-xl border border-slate-100 bg-slate-50/70 p-4"><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p><div className="mt-1.5 text-sm font-semibold text-slate-800">{value}</div></div>;
}

const statusLabels = {attended: 'Attended', absent: 'Absent', not_applicable: 'Not applicable'};
const statusStyles = {attended: 'edway-tone-mint text-emerald-800', absent: 'bg-slate-100 text-slate-700', not_applicable: 'edway-tone-amber text-amber-800'};

function StatusBadge({status}) {
    return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-bold ${statusStyles[status] || 'bg-slate-100 text-slate-700'}`}>{statusLabels[status] || status}</span>;
}

function parseDate(value) {
    if (!value) return null;
    const date = new Date(value);
    return Number.isFinite(date.getTime()) ? date : null;
}

function formatter(options, timeZone) {
    try {
        return new Intl.DateTimeFormat(undefined, timeZone ? {...options, timeZone} : options);
    } catch {
        return new Intl.DateTimeFormat(undefined, options);
    }
}

function formatDate(value, timeZone) {
    const date = parseDate(value);
    if (!date) return '—';
    return formatter({dateStyle: 'medium', timeStyle: 'short'}, timeZone).format(date);
}

function formatTime(value, timeZone) {
    const date = parseDate(value);
    if (!date) return '—';
    return formatter({timeStyle: 'short'}, timeZone).format(date);
}

export function formatDuration(seconds) {
    const total = Number.isFinite(Number(seconds)) ? Math.max(0, Math.floor(Number(seconds))) : 0;
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const remainder = total % 60;
    const parts = [];

    if (hours > 0) parts.push(`${hours}h`);
    if (minutes > 0) parts.push(`${minutes}m`);
    if (remainder > 0) parts.push(`${remainder}s`);

    return parts.length > 0 ? parts.join(' ') : '0m';
}
