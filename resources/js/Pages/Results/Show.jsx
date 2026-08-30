import React from 'react';
import {Head, Link} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';

export default function Show({context = {}, students = [], attendanceSummary = {}, publicationState = 'draft', capabilities = {}}) {
    return <Layout>
        <Head title="Result review"/>
        <Link className="text-sm font-medium text-indigo-600 hover:text-indigo-800" href={context.index_url || '/results'}>← Results</Link>
        <header className="mt-4 flex flex-wrap items-start justify-between gap-4">
            <div><p className="text-sm text-slate-500">{context.academic_year?.name || 'Academic year'} · {context.reporting_period?.name || 'Reporting period'}</p><h1 className="text-2xl font-bold tracking-tight">{context.school_class?.name || 'Class'} · {context.subject?.name || 'Subject'}</h1><p className="mt-1 text-slate-500">Review the server-provided subject result snapshot before publication.</p></div>
            <div className="flex flex-wrap gap-2"><Action label="Review" enabled={capabilities.review}/><Action label="Publish" enabled={capabilities.publish}/><Action label="Correct / republish" enabled={capabilities.correct}/></div>
        </header>

        <dl className="card mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4"><Metric label="Publication state" value={publicationState}/><Metric label="Students" value={students.length || '—'}/><Metric label="Attendance summary" value={attendanceSummary.label || 'Not available'}/><Metric label="Review notes" value={context.review_status || 'Awaiting data'}/></dl>

        <section className="card mt-6 overflow-hidden p-0"><div className="border-b border-slate-200 px-6 py-4"><h2 className="font-semibold">Student result review</h2><p className="mt-1 text-sm text-slate-500">Scores, percentages, attendance and notes are display-only until the results workflow is implemented.</p></div>{students.length > 0 ? <div className="overflow-x-auto"><table className="min-w-full text-left text-sm"><thead className="bg-slate-50 text-slate-500"><tr><th className="px-6 py-3">Student</th><th className="px-6 py-3">Score</th><th className="px-6 py-3">Percentage</th><th className="px-6 py-3">Outcome</th><th className="px-6 py-3">Attendance</th><th className="px-6 py-3">Review status</th></tr></thead><tbody className="divide-y divide-slate-100">{students.map((student) => <tr key={student.public_id || student.id}><td className="px-6 py-4 font-medium text-slate-900">{student.name}</td><td className="px-6 py-4">{student.score_display || '—'}</td><td className="px-6 py-4">{student.percentage_display || '—'}</td><td className="px-6 py-4"><Outcome value={student.outcome}/></td><td className="px-6 py-4 text-slate-600">{student.attendance_display || 'Not available'}</td><td className="px-6 py-4 text-slate-600">{student.review_status || 'Awaiting result data'}</td></tr>)}</tbody></table></div> : <Empty/>}</section>
    </Layout>;
}

function Action({label, enabled}) { return <button type="button" disabled={!enabled} className="btn disabled:cursor-not-allowed" title={enabled ? undefined : 'This action will be available when the results workflow is connected.'}>{label}</button>; }
function Metric({label, value}) { return <div><dt className="text-sm text-slate-500">{label}</dt><dd className="mt-1 font-semibold capitalize text-slate-900">{value}</dd></div>; }
function Outcome({value}) { return value ? <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold capitalize text-slate-700">{value}</span> : <span className="text-slate-400">Not available</span>; }
function Empty() { return <div className="px-6 py-12 text-center"><h3 className="font-semibold text-slate-900">No student result data is available</h3><p className="mt-2 text-sm text-slate-500">The future server response will provide only students within this authorized class and subject context.</p></div>; }
