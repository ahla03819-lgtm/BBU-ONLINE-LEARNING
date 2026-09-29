import React from 'react';
import {Head} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import Icon from '../../Components/UI/Icon';
import EmptyState from '../../Components/UI/EmptyState';
import JoinOrCreateClassPanel from '../../Components/Classes/JoinOrCreateClassPanel';
import ClassCard, {classCardTones} from '../../Components/Classes/ClassCard';
import {useTranslation} from '../../i18n/LocaleProvider';

export default function Index({classes, canCreateClass, canJoinClass, createClassOptions}) {
    const {t} = useTranslation();
    return <Layout><Head title={t('classes.title')}/><div className="mx-auto max-w-7xl"><header className="bbu-page-hero rounded-3xl px-6 py-7 sm:px-8"><div className="flex flex-wrap items-start justify-between gap-5"><div className="flex items-start gap-4"><span className="bbu-primary-icon inline-flex h-12 w-12 items-center justify-center rounded-2xl text-white"><Icon name="school" className="h-6 w-6"/></span><div><p className="text-sm font-bold text-sky-700">{t('classes.learningWorkspaces')}</p><h1 className="mt-1 text-3xl font-bold tracking-tight text-slate-900">{t('classes.title')}</h1><p className="mt-2 max-w-xl text-sm leading-6 text-slate-600">{t('classes.openWorkspaces')}</p></div></div><JoinOrCreateClassPanel canJoinClass={canJoinClass} canCreateClass={canCreateClass} createClassOptions={createClassOptions}/></div></header><section className="mt-7"><div className="flex flex-wrap items-end justify-between gap-3"><div><h2 className="text-xl font-bold text-slate-900">{t('classes.myClasses')}</h2><p className="mt-1 text-sm text-slate-500">{t('classes.onlyClassesListed')}</p></div><span className="rounded-full bg-sky-50 px-3 py-1 text-xs font-bold text-sky-800">{t(classes.length === 1 ? 'classes.workspaceCount' : 'classes.workspaceCountOther', {count: classes.length})}</span></div>{classes.length ? <div className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{classes.map((schoolClass, index) => <ClassCard key={schoolClass.id} schoolClass={schoolClass} tone={classCardTones[index % classCardTones.length]} actionLabel={t('classes.openWorkspace')} count={schoolClass.memberCount}/>)}</div> : <div className="mt-5"><EmptyState text={t('classes.noClasses')}/></div>}</section></div></Layout>;
}
