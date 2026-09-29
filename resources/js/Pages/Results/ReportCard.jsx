import React from 'react';
import {Head, Link} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import {useTranslation} from '../../i18n/LocaleProvider';

const statusLabelKeys = {published: 'results.published', review: 'results.review', draft: 'results.draft'};

export default function ReportCard({student = {}, academicYear = {}, reportingPeriod = {}, schoolClass = {}, results = [], attendanceSummary = {}}) {
    const {t} = useTranslation();
    const periodState = String(reportingPeriod.publication_state || '').toLowerCase();
    return <Layout>
        <Head title={t('results.reportCard')}/>
        <Link className="text-sm font-medium text-indigo-600 hover:text-indigo-800" href="/my-results">← {t('results.myResults')}</Link>
        <article className="card mx-auto mt-4 max-w-4xl">
            <header className="border-b border-slate-200 pb-6 text-center"><p className="text-sm font-semibold tracking-wide text-sky-700">BBU ONLINE LEARNING</p><h1 className="mt-1 text-2xl font-bold tracking-tight text-slate-900">{t('results.reportCardTitle')}</h1><p className="mt-2 text-sm text-slate-500">{academicYear.name || t('results.academicYear')} · {reportingPeriod.name || t('results.reportingPeriod')}</p></header>
            <dl className="grid gap-4 py-6 sm:grid-cols-2"><Detail label={t('results.student')} value={student.name || t('results.studentDetailsUnavailable')}/><Detail label={t('results.class')} value={schoolClass.name || t('results.classDetailsUnavailable')}/><Detail label={t('results.reportingPeriodStatus')} value={periodState ? t(statusLabelKeys[periodState] || periodState) : t('results.unavailable')}/><Detail label={t('results.attendanceSummary')} value={attendanceSummary.label || t('results.notAvailable')}/></dl>
            <div className="overflow-x-auto rounded-lg border border-slate-200"><table className="min-w-full text-left text-sm"><thead className="bg-slate-50 text-slate-500"><tr><th className="px-5 py-3">{t('results.subject')}</th><th className="px-5 py-3">{t('results.score')}</th><th className="px-5 py-3">{t('results.percentage')}</th><th className="px-5 py-3">{t('results.outcome')}</th></tr></thead><tbody className="divide-y divide-slate-100">{results.length > 0 ? results.map((result) => <tr key={result.public_id || result.subject?.name}><td className="px-5 py-4 font-medium text-slate-900">{result.subject?.name}</td><td className="px-5 py-4">{result.score_display || '—'}</td><td className="px-5 py-4">{result.percentage_display || '—'}</td><td className="px-5 py-4 capitalize">{result.outcome || '—'}</td></tr>) : <tr><td className="px-5 py-10 text-center text-slate-500" colSpan="4">{t('results.noPublishedReportCard')}</td></tr>}</tbody></table></div>
            <footer className="mt-6 rounded-lg bg-slate-50 p-4 text-sm text-slate-600">{t('results.reportCardFoundation')}</footer>
        </article>
    </Layout>;
}

function Detail({label, value}) { return <div><dt className="text-sm text-slate-500">{label}</dt><dd className="mt-1 font-semibold capitalize text-slate-900">{value}</dd></div>; }
