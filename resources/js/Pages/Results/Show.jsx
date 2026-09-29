import React from 'react';
import {Head, Link} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import {useTranslation} from '../../i18n/LocaleProvider';

const statusLabelKeys = {published: 'results.published', review: 'results.review', draft: 'results.draft', unavailable: 'results.notAvailable'};

export default function Show({context = {}, students = [], attendanceSummary = {}, publicationState = 'draft', capabilities = {}}) {
    const {t} = useTranslation();
    const state = String(publicationState || '').toLowerCase();
    return <Layout>
        <Head title={t('results.resultReview')}/>
        <Link className="text-sm font-medium text-indigo-600 hover:text-indigo-800" href={context.index_url || '/results'}>← {t('results.title')}</Link>
        <header className="mt-4 flex flex-wrap items-start justify-between gap-4">
            <div><p className="text-sm text-slate-500">{context.academic_year?.name || t('results.academicYear')} · {context.reporting_period?.name || t('results.reportingPeriod')}</p><h1 className="text-2xl font-bold tracking-tight">{context.school_class?.name || t('results.class')} · {context.subject?.name || t('results.subject')}</h1><p className="mt-1 text-slate-500">{t('results.reviewDescription')}</p></div>
            <div className="flex flex-wrap gap-2"><Action label={t('results.review')} enabled={capabilities.review}/><Action label={t('results.publish')} enabled={capabilities.publish}/><Action label={t('results.correctAndRepublish')} enabled={capabilities.correct}/></div>
        </header>

        <dl className="card mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4"><Metric label={t('results.publicationState')} value={state ? t(statusLabelKeys[state] || state) : t('results.notAvailable')}/><Metric label={t('results.students')} value={students.length || '—'}/><Metric label={t('results.attendanceSummary')} value={attendanceSummary.label || t('results.notAvailable')}/><Metric label={t('results.reviewNotes')} value={context.review_status || t('results.awaitingData')}/></dl>

        <section className="card mt-6 overflow-hidden p-0"><div className="border-b border-slate-200 px-6 py-4"><h2 className="font-semibold">{t('results.studentResultReview')}</h2><p className="mt-1 text-sm text-slate-500">{t('results.studentResultReviewDescription')}</p></div>{students.length > 0 ? <div className="overflow-x-auto"><table className="min-w-full text-left text-sm"><thead className="bg-slate-50 text-slate-500"><tr><th className="px-6 py-3">{t('results.student')}</th><th className="px-6 py-3">{t('results.score')}</th><th className="px-6 py-3">{t('results.percentage')}</th><th className="px-6 py-3">{t('results.outcome')}</th><th className="px-6 py-3">{t('results.attendance')}</th><th className="px-6 py-3">{t('results.reviewStatus')}</th></tr></thead><tbody className="divide-y divide-slate-100">{students.map((student) => <tr key={student.public_id || student.id}><td className="px-6 py-4 font-medium text-slate-900">{student.name}</td><td className="px-6 py-4">{student.score_display || '—'}</td><td className="px-6 py-4">{student.percentage_display || '—'}</td><td className="px-6 py-4"><Outcome value={student.outcome}/></td><td className="px-6 py-4 text-slate-600">{student.attendance_display || t('results.notAvailable')}</td><td className="px-6 py-4 text-slate-600">{student.review_status || t('results.awaitingResultData')}</td></tr>)}</tbody></table></div> : <Empty/>}</section>
    </Layout>;
}

function Action({label, enabled}) { const {t} = useTranslation(); return <button type="button" disabled={!enabled} className="btn disabled:cursor-not-allowed" title={enabled ? undefined : t('results.actionUnavailable')}>{label}</button>; }
function Metric({label, value}) { return <div><dt className="text-sm text-slate-500">{label}</dt><dd className="mt-1 font-semibold capitalize text-slate-900">{value}</dd></div>; }
function Outcome({value}) { const {t} = useTranslation(); return value ? <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold capitalize text-slate-700">{value}</span> : <span className="text-slate-400">{t('results.notAvailable')}</span>; }
function Empty() { const {t} = useTranslation(); return <div className="px-6 py-12 text-center"><h3 className="font-semibold text-slate-900">{t('results.noStudentResultData')}</h3><p className="mt-2 text-sm text-slate-500">{t('results.noStudentResultDataHint')}</p></div>; }
