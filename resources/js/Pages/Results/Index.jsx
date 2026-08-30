import React from 'react';
import {Head, Link} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';

const statusClass = (status) => ({published: 'bg-emerald-100 text-emerald-800', review: 'bg-amber-100 text-amber-800', draft: 'bg-slate-100 text-slate-700'}[status] || 'bg-slate-100 text-slate-700');

export default function Index({filters = {}, reportingPeriods = [], classes = [], subjects = [], results = [], summary = {}}) {
    return <Layout>
        <Head title="Results"/>
        <header className="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <p className="text-sm font-medium text-indigo-600">Academic results</p>
                <h1 className="text-2xl font-bold tracking-tight text-slate-900">Results management</h1>
                <p className="mt-1 max-w-2xl text-slate-500">Review subject results by reporting period before they are shared with students.</p>
            </div>
            <span className="rounded-full bg-slate-100 px-3 py-1 text-sm font-medium text-slate-600">Server-connected filters coming soon</span>
        </header>

        <section aria-label="Result filters" className="card mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Filter label="Academic year" value={filters.academic_year_name} options={filters.academic_years || []}/>
            <Filter label="Reporting period" value={filters.reporting_period_id} options={reportingPeriods}/>
            <Filter label="Class" value={filters.school_class_id} options={classes}/>
            <Filter label="Subject" value={filters.class_subject_id} options={subjects}/>
        </section>

        <dl className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Summary label="Students in scope" value={summary.students ?? '—'}/>
            <Summary label="Ready for review" value={summary.ready_for_review ?? '—'}/>
            <Summary label="Published" value={summary.published ?? '—'}/>
            <Summary label="Needs attention" value={summary.needs_attention ?? '—'}/>
        </dl>

        <section className="card overflow-hidden p-0">
            <div className="border-b border-slate-200 px-6 py-4"><h2 className="font-semibold text-slate-900">Subject results</h2><p className="mt-1 text-sm text-slate-500">Numeric values and publication state are supplied by the future results domain.</p></div>
            {results.length > 0 ? <div className="overflow-x-auto"><table className="min-w-full text-left text-sm"><thead className="bg-slate-50 text-slate-500"><tr><th className="px-6 py-3 font-medium">Student</th><th className="px-6 py-3 font-medium">Subject</th><th className="px-6 py-3 font-medium">Result</th><th className="px-6 py-3 font-medium">Status</th><th className="px-6 py-3 font-medium">Last updated</th><th className="px-6 py-3"><span className="sr-only">Review</span></th></tr></thead><tbody className="divide-y divide-slate-100">{results.map((result) => <tr key={result.public_id || `${result.student?.name}-${result.subject?.name}`}><td className="px-6 py-4 font-medium text-slate-900">{result.student?.name}</td><td className="px-6 py-4 text-slate-600">{result.subject?.name}</td><td className="px-6 py-4 text-slate-900">{result.score_display || 'Not available'}</td><td className="px-6 py-4"><Status value={result.publication_state || result.status}/></td><td className="px-6 py-4 text-slate-500">{result.updated_at_display || '—'}</td><td className="px-6 py-4 text-right">{result.review_url ? <Link className="text-sm font-medium text-indigo-600 hover:text-indigo-800" href={result.review_url}>Review<span className="sr-only"> {result.student?.name}</span></Link> : <span className="text-sm text-slate-400">Unavailable</span>}</td></tr>)}</tbody></table></div> : <EmptyState/>}
        </section>
    </Layout>;
}

function Filter({label, value, options}) { return <label className="text-sm font-medium text-slate-700">{label}<select disabled value={value || ''} className="mt-1 block w-full rounded-lg border-slate-300 bg-slate-50 text-slate-500 disabled:cursor-not-allowed" aria-describedby={`${label.replaceAll(' ', '-').toLowerCase()}-help`}><option>{value || `All ${label.toLowerCase()}s`}</option>{options.map((option) => <option key={option.id || option.public_id} value={option.id || option.public_id}>{option.name || option.label}</option>)}</select><span id={`${label.replaceAll(' ', '-').toLowerCase()}-help`} className="sr-only">Filtering will be available when the results domain is connected.</span></label>; }
function Summary({label, value}) { return <div className="card p-4"><dt className="text-sm text-slate-500">{label}</dt><dd className="mt-1 text-2xl font-semibold text-slate-900">{value}</dd></div>; }
function Status({value}) { const normalized = String(value || 'draft').toLowerCase(); return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold capitalize ${statusClass(normalized)}`}>{normalized}</span>; }
function EmptyState() { return <div className="px-6 py-12 text-center"><h3 className="font-semibold text-slate-900">No results to review yet</h3><p className="mx-auto mt-2 max-w-md text-sm text-slate-500">Results will appear here after a reporting period and server-authoritative result data are available.</p></div>; }
