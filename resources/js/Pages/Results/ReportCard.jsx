import React from 'react';
import {Head, Link} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';

export default function ReportCard({student = {}, academicYear = {}, reportingPeriod = {}, schoolClass = {}, results = [], attendanceSummary = {}}) {
    return <Layout>
        <Head title="Report Card"/>
        <Link className="text-sm font-medium text-indigo-600 hover:text-indigo-800" href="/my-results">← My Results</Link>
        <article className="card mx-auto mt-4 max-w-4xl">
            <header className="border-b border-slate-200 pb-6 text-center"><p className="text-sm font-semibold tracking-wide text-sky-700">BBU ONLINE LEARNING</p><h1 className="mt-1 text-2xl font-bold tracking-tight text-slate-900">Academic Report Card</h1><p className="mt-2 text-sm text-slate-500">{academicYear.name || 'Academic year'} · {reportingPeriod.name || 'Reporting period'}</p></header>
            <dl className="grid gap-4 py-6 sm:grid-cols-2"><Detail label="Student" value={student.name || 'Student details unavailable'}/><Detail label="Class" value={schoolClass.name || 'Class details unavailable'}/><Detail label="Reporting period status" value={reportingPeriod.publication_state || 'Unavailable'}/><Detail label="Attendance summary" value={attendanceSummary.label || 'Not available'}/></dl>
            <div className="overflow-x-auto rounded-lg border border-slate-200"><table className="min-w-full text-left text-sm"><thead className="bg-slate-50 text-slate-500"><tr><th className="px-5 py-3">Subject</th><th className="px-5 py-3">Score</th><th className="px-5 py-3">Percentage</th><th className="px-5 py-3">Outcome</th></tr></thead><tbody className="divide-y divide-slate-100">{results.length > 0 ? results.map((result) => <tr key={result.public_id || result.subject?.name}><td className="px-5 py-4 font-medium text-slate-900">{result.subject?.name}</td><td className="px-5 py-4">{result.score_display || '—'}</td><td className="px-5 py-4">{result.percentage_display || '—'}</td><td className="px-5 py-4 capitalize">{result.outcome || '—'}</td></tr>) : <tr><td className="px-5 py-10 text-center text-slate-500" colSpan="4">No published report-card results are available.</td></tr>}</tbody></table></div>
            <footer className="mt-6 rounded-lg bg-slate-50 p-4 text-sm text-slate-600">This is an online report-card view. Export, ranking, grade-scale, and GPA features are not included in this foundation.</footer>
        </article>
    </Layout>;
}

function Detail({label, value}) { return <div><dt className="text-sm text-slate-500">{label}</dt><dd className="mt-1 font-semibold capitalize text-slate-900">{value}</dd></div>; }
