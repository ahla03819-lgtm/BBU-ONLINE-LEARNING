import React, {useState} from 'react';
import {useForm} from '@inertiajs/react';
import Icon from '../UI/Icon';
import {useTranslation} from '../../i18n/LocaleProvider';

export default function JoinOrCreateClassPanel({canJoinClass, canCreateClass, createClassOptions}) {
    const {t} = useTranslation();
    const [open, setOpen] = useState(false);
    const join = useForm({code: ''});
    const create = useForm({
        academic_year_id: createClassOptions?.academicYears?.[0]?.id ?? '',
        grade_level_id: createClassOptions?.gradeLevels?.[0]?.id ?? '',
        name: '', section: '', status: 'active', capacity: '',
    });
    const canOpen = canJoinClass || canCreateClass;

    if (!canOpen) return null;

    const submitJoin = (event) => {
        event.preventDefault();
        join.post('/classes/join', {preserveScroll: true});
    };
    const submitCreate = (event) => {
        event.preventDefault();
        create.post('/school-classes', {
            preserveScroll: true,
            onSuccess: () => { create.reset('name', 'section', 'capacity'); setOpen(false); },
        });
    };

    return <>
        <button type="button" onClick={() => setOpen(true)} className="btn"><Icon name="plus" className="h-4 w-4"/>{t('classes.joinOrCreateClass')}</button>
        {open && <div className="fixed inset-0 z-50 overflow-y-auto p-4 sm:p-6" role="dialog" aria-modal="true" aria-labelledby="join-create-class-title">
            <button type="button" aria-label="Close join or create class" onClick={() => setOpen(false)} className="fixed inset-0 bg-slate-950/40 backdrop-blur-[1px]"/>
            <div className="relative mx-auto my-4 w-full max-w-2xl rounded-3xl border border-slate-200 bg-white p-5 shadow-2xl sm:my-10 sm:p-7">
                <div className="flex items-start justify-between gap-4"><div><p className="text-xs font-bold uppercase tracking-[.14em] text-sky-700">BBU ONLINE LEARNING</p><h2 id="join-create-class-title" className="mt-1 text-2xl font-bold tracking-tight text-slate-900">{t('classes.joinOrCreateClass')}</h2><p className="mt-2 text-sm leading-6 text-slate-600">{t('classes.joinOrCreateClassDescription')}</p></div><button type="button" onClick={() => setOpen(false)} className="rounded-xl p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-600" aria-label="Close"><Icon name="x" className="h-5 w-5"/></button></div>
                <div className={`mt-6 grid gap-5 ${canJoinClass && canCreateClass ? 'lg:grid-cols-2' : ''}`}>
                    {canJoinClass && <section className="rounded-2xl border border-sky-100 bg-sky-50/55 p-5"><div className="flex items-start gap-3"><span className="bbu-primary-icon inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-white"><Icon name="school" className="h-5 w-5"/></span><div><h3 className="font-bold text-slate-900">{t('classes.joinAClass')}</h3><p className="mt-1 text-sm leading-5 text-slate-600">{t('classes.joinClassDescription')}</p></div></div><form className="mt-5 space-y-3" onSubmit={submitJoin}><div><label htmlFor="class-code" className="text-sm font-semibold text-slate-700">{t('classes.classCode')}</label><input id="class-code" value={join.data.code} onChange={(event) => join.setData('code', event.target.value)} placeholder={t('classes.classCodePlaceholder')} autoComplete="off" className="field mt-1.5 font-mono uppercase tracking-[.14em]" aria-describedby={join.errors.code ? 'class-code-error' : undefined}/>{join.errors.code && <p id="class-code-error" className="mt-1.5 text-sm font-medium text-rose-700">{join.errors.code}</p>}</div><button className="btn w-full" disabled={join.processing}>{join.processing ? t('classes.joiningClass') : t('classes.joinClass')}</button></form></section>}
                    {canCreateClass && <section className="rounded-2xl border border-slate-200 bg-slate-50/75 p-5"><div className="flex items-start gap-3"><span className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-white"><Icon name="plus" className="h-5 w-5"/></span><div><h3 className="font-bold text-slate-900">{t('classes.createAClass')}</h3><p className="mt-1 text-sm leading-5 text-slate-600">{t('classes.createClassDescription')}</p></div></div>{createClassOptions?.academicYears?.length && createClassOptions?.gradeLevels?.length ? <form className="mt-5 grid gap-3" onSubmit={submitCreate}><Select label={t('classes.academicYear')} id="academic-year" value={create.data.academic_year_id} onChange={(value) => create.setData('academic_year_id', value)} options={createClassOptions.academicYears}/><Select label={t('classes.gradeLevel')} id="grade-level" value={create.data.grade_level_id} onChange={(value) => create.setData('grade_level_id', value)} options={createClassOptions.gradeLevels}/><Field label={t('classes.className')} id="class-name" value={create.data.name} onChange={(value) => create.setData('name', value)} error={create.errors.name}/><div className="grid gap-3 sm:grid-cols-2"><Field label={t('classes.section')} id="class-section" value={create.data.section} onChange={(value) => create.setData('section', value)} error={create.errors.section}/><Field label={t('classes.capacity')} id="class-capacity" type="number" value={create.data.capacity} onChange={(value) => create.setData('capacity', value)} error={create.errors.capacity}/></div><Select label={t('classes.classStatus')} id="class-status" value={create.data.status} onChange={(value) => create.setData('status', value)} options={(createClassOptions.statuses || []).map((status) => ({id: status, name: status[0].toUpperCase()+status.slice(1)}))}/>{Object.entries(create.errors).filter(([key]) => ! ['name', 'section', 'capacity'].includes(key)).map(([key, message]) => <p className="text-sm font-medium text-rose-700" key={key}>{message}</p>)}<button className="btn mt-1 w-full" disabled={create.processing}>{create.processing ? t('classes.creatingClass') : t('classes.createClass')}</button></form> : <p className="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm leading-5 text-amber-900">{t('classes.createClassPrerequisite')}</p>}</section>}
                </div>
            </div>
        </div>}
    </>;
}

function Select({label, id, value, onChange, options}) {
    return <label className="block text-sm font-semibold text-slate-700">{label}<select id={id} className="field mt-1.5" value={value} onChange={(event) => onChange(event.target.value)}>{options.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}</select></label>;
}

function Field({label, id, type = 'text', value, onChange, error}) {
    return <div><label htmlFor={id} className="text-sm font-semibold text-slate-700">{label}</label><input id={id} type={type} value={value} onChange={(event) => onChange(event.target.value)} className="field mt-1.5"/>{error && <p className="mt-1.5 text-sm font-medium text-rose-700">{error}</p>}</div>;
}
