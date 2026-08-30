import React from 'react';
import {Head, Link} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';

export default function MyResults({academicYear = {}, periods = [], selectedPeriod = {}, results = [], attendanceSummary = {}}) {
    return <Layout>
        <Head title="My Results"/>
        <header className="mb-6"><p className="text-sm font-medium text-indigo-600">Academic progress</p><h1 className="text-2xl font-bold tracking-tight">My Results</h1><p className="mt-1 text-slate-500">View your published subject results and attendance summary.</p></header>
        <section className="card mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div><p className="text-sm text-slate-500">Academic year</p><p className="font-semibold text-slate-900">{academicYear.name || 'Academic year not selected'}</p></div><label className="text-sm font-medium text-slate-700">Reporting period<select disabled value={selectedPeriod.public_id || ''} className="mt-1 block w-full rounded-lg border-slate-300 bg-slate-50 text-slate-500 disabled:cursor-not-allowed"><option>{selectedPeriod.name || 'No reporting period selected'}</option>{periods.map((period) => <option key={period.public_id || period.id} value={period.public_id || period.id}>{period.name}</option>)}</select></label></section>
        <section className="grid gap-3 sm:grid-cols-2"><Summary label="Attendance" value={attendanceSummary.label || 'Not available'} detail={attendanceSummary.detail}/><Summary label="Publication" value={selectedPeriod.publication_state || 'Results not available'} detail="Only published results will be shown here."/></section>
        <section className="mt-6 grid gap-4 md:grid-cols-2">{results.length > 0 ? results.map((result) => <article className="card" key={result.public_id || result.subject?.name}><div className="flex items-start justify-between gap-3"><div><p className="text-sm text-slate-500">{result.school_class?.name}</p><h2 className="text-lg font-semibold text-slate-900">{result.subject?.name}</h2></div><Status value={result.publication_state}/></div><dl className="mt-5 grid grid-cols-2 gap-4"><ResultMetric label="Score" value={result.score_display || '—'}/><ResultMetric label="Percentage" value={result.percentage_display || '—'}/></dl><p className="mt-4 text-sm text-slate-600">{result.outcome || 'Outcome will be available after results are published.'}</p>{result.report_card_url && <Link href={result.report_card_url} className="mt-4 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-800">View report card</Link>}</article>) : <Empty/>}</section>
    </Layout>;
}

function Summary({label, value, detail}) { return <div className="card p-4"><dt className="text-sm text-slate-500">{label}</dt><dd className="mt-1 font-semibold text-slate-900">{value}</dd>{detail && <p className="mt-1 text-sm text-slate-500">{detail}</p>}</div>; }
function ResultMetric({label, value}) { return <div><dt className="text-sm text-slate-500">{label}</dt><dd className="mt-1 text-xl font-semibold text-slate-900">{value}</dd></div>; }
function Status({value}) { return <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold capitalize text-slate-700">{value || 'Unavailable'}</span>; }
function Empty() { return <div className="card text-center md:col-span-2"><h2 className="font-semibold text-slate-900">No published results yet</h2><p className="mx-auto mt-2 max-w-md text-sm text-slate-500">Your results will appear here when your school publishes an authorized reporting period.</p></div>; }
