import React from 'react';
import {Head, Link} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import {useTranslation} from '../../i18n/LocaleProvider';

const statusLabelKeys = {published: 'results.published', review: 'results.review', draft: 'results.draft'};

export default function MyResults({academicYear = {}, periods = [], selectedPeriod = {}, results = [], attendanceSummary = {}}) {
    const {t} = useTranslation();
    const statusLabel = (value) => { const normalized = String(value || '').toLowerCase(); return normalized ? t(statusLabelKeys[normalized] || normalized) : t('results.resultsNotAvailable'); };
    return <Layout>
        <Head title={t('results.myResults')}/>
        <header className="mb-6"><p className="text-sm font-medium text-indigo-600">{t('results.academicProgress')}</p><h1 className="text-2xl font-bold tracking-tight">{t('results.myResults')}</h1><p className="mt-1 text-slate-500">{t('results.myResultsDescription')}</p></header>
        <section className="card mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div><p className="text-sm text-slate-500">{t('results.academicYear')}</p><p className="font-semibold text-slate-900">{academicYear.name || t('results.academicYearNotSelected')}</p></div><label className="text-sm font-medium text-slate-700">{t('results.reportingPeriod')}<select disabled value={selectedPeriod.public_id || ''} className="mt-1 block w-full rounded-lg border-slate-300 bg-slate-50 text-slate-500 disabled:cursor-not-allowed"><option>{selectedPeriod.name || t('results.noReportingPeriodSelected')}</option>{periods.map((period) => <option key={period.public_id || period.id} value={period.public_id || period.id}>{period.name}</option>)}</select></label></section>
        <section className="grid gap-3 sm:grid-cols-2"><Summary label={t('results.attendance')} value={attendanceSummary.label || t('results.notAvailable')} detail={attendanceSummary.detail}/><Summary label={t('results.publication')} value={statusLabel(selectedPeriod.publication_state)} detail={t('results.onlyPublished')}/></section>
        <section className="mt-6 grid gap-4 md:grid-cols-2">{results.length > 0 ? results.map((result) => <article className="card" key={result.public_id || result.subject?.name}><div className="flex items-start justify-between gap-3"><div><p className="text-sm text-slate-500">{result.school_class?.name}</p><h2 className="text-lg font-semibold text-slate-900">{result.subject?.name}</h2></div><Status value={result.publication_state}/></div><dl className="mt-5 grid grid-cols-2 gap-4"><ResultMetric label={t('results.score')} value={result.score_display || '—'}/><ResultMetric label={t('results.percentage')} value={result.percentage_display || '—'}/></dl><p className="mt-4 text-sm text-slate-600">{result.outcome || t('results.outcomeUnavailable')}</p>{result.report_card_url && <Link href={result.report_card_url} className="mt-4 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-800">{t('results.viewReportCard')}</Link>}</article>) : <Empty/>}</section>
    </Layout>;
}

function Summary({label, value, detail}) { return <div className="card p-4"><dt className="text-sm text-slate-500">{label}</dt><dd className="mt-1 font-semibold text-slate-900">{value}</dd>{detail && <p className="mt-1 text-sm text-slate-500">{detail}</p>}</div>; }
function ResultMetric({label, value}) { return <div><dt className="text-sm text-slate-500">{label}</dt><dd className="mt-1 text-xl font-semibold text-slate-900">{value}</dd></div>; }
function Status({value}) { const {t} = useTranslation(); const normalized = String(value || '').toLowerCase(); return <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold capitalize text-slate-700">{normalized ? t(statusLabelKeys[normalized] || normalized) : t('results.unavailable')}</span>; }
function Empty() { const {t} = useTranslation(); return <div className="card text-center md:col-span-2"><h2 className="font-semibold text-slate-900">{t('results.noPublishedResults')}</h2><p className="mx-auto mt-2 max-w-md text-sm text-slate-500">{t('results.noPublishedResultsHint')}</p></div>; }
