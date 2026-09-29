import React, {useEffect, useRef, useState} from 'react';
import {Head, Link, router, useForm} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import Icon from '../../Components/UI/Icon';
import UserAvatar from '../../Components/UI/UserAvatar';
import {useAppSounds} from '../../Sound/AppSounds';
import {useTranslation} from '../../i18n/LocaleProvider';

// Languages are listed in their own language so the choice reads the same everywhere.
const languageOptions = [{value: 'en', label: 'English', short: 'EN'}, {value: 'km', label: 'ខ្មែរ', short: 'ខ្មែរ'}];

const sections = [
    {key: 'profile', label: 'account.sections.profile', description: 'account.descriptions.profile', icon: 'user'},
    {key: 'security', label: 'account.sections.security', description: 'account.descriptions.security', icon: 'settings'},
    {key: 'notifications', label: 'account.sections.notifications', description: 'account.descriptions.notifications', icon: 'bell'},
    {key: 'appearance', label: 'account.sections.appearance', description: 'account.descriptions.appearance', icon: 'spark'},
];

export default function Show({section, account}) {
    const {t} = useTranslation();
    const current = sections.find((item) => item.key === section) || sections[0];

    return <Layout>
        <Head title={t('account.title')}/>
        <div className="mx-auto max-w-6xl space-y-6">
            <header className="overflow-hidden rounded-3xl border border-sky-100 bg-[linear-gradient(120deg,#edf7ff,#fff_58%,#eef5ff)] px-6 py-7 shadow-[0_12px_28px_rgba(7,92,168,.08)] sm:px-8">
                <div className="flex flex-wrap items-center gap-4"><UserAvatar name={account.name} avatarUrl={account.avatar_url} size="md" alt={`Profile photo for ${account.name}`}/><div><p className="text-xs font-bold uppercase tracking-[.16em] text-sky-700">BBU ONLINE LEARNING</p><h1 className="mt-1 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">{t('account.title')}</h1><p className="mt-2 text-sm text-slate-600">{t('account.subtitle')}</p></div></div>
            </header>
            <div className="grid gap-6 lg:grid-cols-[minmax(17.5rem,20rem)_minmax(0,1fr)]">
                <nav className="grid gap-2 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm sm:grid-cols-2 lg:block lg:space-y-1" aria-label={t('account.settingsLabel')}>
                    {sections.map((item) => <Link key={item.key} href={item.key === 'profile' ? '/my-account' : `/my-account/${item.key}`} className={`min-w-0 rounded-xl px-3 py-3 text-left transition focus:outline-none focus:ring-2 focus:ring-sky-600 lg:block ${item.key === current.key ? 'bg-sky-50 text-sky-800' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950'}`} aria-current={item.key === current.key ? 'page' : undefined}><span className="flex min-w-0 items-start gap-3"><span className={`grid h-8 w-8 shrink-0 place-items-center rounded-lg ${item.key === current.key ? 'bg-white text-sky-700 shadow-sm' : 'bg-slate-100 text-slate-500'}`}><Icon name={item.icon} className="h-4 w-4"/></span><span className="min-w-0"><span className="block text-sm font-bold leading-5">{t(item.label)}</span><span className="mt-0.5 block text-xs font-medium leading-4 text-slate-500">{t(item.description)}</span></span></span></Link>)}
                </nav>
                <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_10px_24px_rgba(15,23,42,.05)] sm:p-7">
                    <div className="border-b border-slate-100 pb-5"><div className="flex items-center gap-3"><span className="grid h-10 w-10 place-items-center rounded-xl bg-sky-50 text-sky-700"><Icon name={current.icon} className="h-5 w-5"/></span><div><h2 className="text-xl font-bold text-slate-950">{t(current.label)}</h2><p className="mt-1 text-sm text-slate-600">{t(current.description)}</p></div></div></div>
                    {section === 'security' ? <Security/> : section === 'notifications' ? <NotificationsSounds/> : section === 'appearance' ? <Appearance/> : <Profile account={account}/>}
                </section>
            </div>
        </div>
    </Layout>;
}

function Profile({account}) {
    const {t} = useTranslation();
    const form = useForm({name: account.name});
    const submit = (event) => { event.preventDefault(); form.patch('/my-account/profile'); };

    return <div className="mt-6 space-y-7"><AvatarPhoto account={account}/><form className="max-w-2xl space-y-5" onSubmit={submit}>
        <label className="block"><span className="text-sm font-bold text-slate-800">{t('account.profile.fullName')}</span><input className="input mt-2" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} autoComplete="name" required/>{form.errors.name && <p className="mt-2 text-sm font-medium text-red-600">{form.errors.name}</p>}</label>
        <ReadOnlyField label={t('account.profile.email')} value={account.email} hint={t('account.profile.emailHint')}/>
        <ReadOnlyField label={t('account.profile.role')} value={account.roles.join(', ') || t('account.profile.noRole')} hint={t('account.profile.roleHint')}/>
        {account.context && <ReadOnlyField label={t('account.profile.accountContext')} value={`${account.context.label}${account.context.identifier ? ` · ${account.context.identifier}` : ''}`} hint={t('account.profile.contextHint')}/>}
        <div className="flex justify-end border-t border-slate-100 pt-5"><button className="btn" disabled={form.processing}>{t(form.processing ? 'common.saving' : 'account.profile.saveProfile')}</button></div>
    </form></div>;
}

function AvatarPhoto({account}) {
    const {t} = useTranslation();
    const fileInput = useRef(null);
    const photoForm = useForm({avatar: null});
    const [previewUrl, setPreviewUrl] = useState(null);
    const [fileName, setFileName] = useState('');
    const [removing, setRemoving] = useState(false);

    useEffect(() => () => { if (previewUrl) URL.revokeObjectURL(previewUrl); }, [previewUrl]);

    const clearSelection = () => {
        setPreviewUrl(null);
        setFileName('');
        photoForm.reset();
        photoForm.clearErrors();
        if (fileInput.current) fileInput.current.value = '';
    };
    const selectPhoto = (event) => {
        const file = event.target.files?.[0];
        if (! file) return;
        photoForm.setData('avatar', file);
        photoForm.clearErrors('avatar');
        setFileName(file.name);
        setPreviewUrl(URL.createObjectURL(file));
    };
    const savePhoto = () => photoForm.post('/my-account/avatar', {forceFormData: true, preserveScroll: true, onSuccess: clearSelection});
    const removePhoto = () => router.delete('/my-account/avatar', {preserveScroll: true, onStart: () => setRemoving(true), onFinish: () => setRemoving(false)});

    return <section className="rounded-2xl border border-sky-100 bg-[linear-gradient(135deg,#f4f9ff,#fff)] p-5 shadow-sm"><div className="flex flex-col gap-5 sm:flex-row sm:items-center"><UserAvatar name={account.name} avatarUrl={previewUrl || account.avatar_url} size="lg" alt={previewUrl ? `Preview of the selected profile photo for ${account.name}` : `Profile photo for ${account.name}`}/><div className="min-w-0 flex-1"><p className="text-sm font-bold text-slate-950">{t('account.profile.profilePhoto')}</p><p className="mt-1 max-w-lg text-sm leading-6 text-slate-600">Choose a JPG, PNG, or WEBP image up to 2 MB. Your photo appears anywhere BBU ONLINE LEARNING shows your account.</p>{fileName && <p className="mt-2 truncate text-xs font-semibold text-sky-800">Selected: {fileName}</p>}<input ref={fileInput} type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={selectPhoto} aria-label="Choose a profile photo"/>{photoForm.errors.avatar && <p className="mt-2 text-sm font-medium text-red-600" role="alert">{photoForm.errors.avatar}</p>}<div className="mt-4 flex flex-wrap gap-2">{previewUrl ? <><button type="button" onClick={savePhoto} disabled={photoForm.processing} className="btn">{t(photoForm.processing ? 'account.profile.savingPhoto' : 'account.profile.savePhoto')}</button><button type="button" onClick={clearSelection} disabled={photoForm.processing} className="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-sky-600">{t('common.cancel')}</button></> : <button type="button" onClick={() => fileInput.current?.click()} disabled={photoForm.processing || removing} className="rounded-xl border border-sky-200 bg-white px-4 py-2.5 text-sm font-bold text-sky-800 shadow-sm transition hover:bg-sky-50 focus:outline-none focus:ring-2 focus:ring-sky-600">{t('account.profile.changePhoto')}</button>}{account.avatar_url && ! previewUrl && <button type="button" onClick={removePhoto} disabled={removing} className="rounded-xl border border-red-200 bg-white px-4 py-2.5 text-sm font-bold text-red-700 transition hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500">{removing ? 'Removing…' : 'Remove photo'}</button>}</div></div></div></section>;
}

function Security() {
    const {t} = useTranslation();
    const form = useForm({current_password: '', password: '', password_confirmation: ''});
    const submit = (event) => { event.preventDefault(); form.put('/my-account/security', {onSuccess: () => form.reset()}); };

    return <div className="mt-6 space-y-8"><form className="max-w-xl space-y-5" onSubmit={submit}><p className="text-sm leading-6 text-slate-600">Choose a strong password and keep it private. Your current password is required before it can be changed.</p><PasswordField label="Current password" name="current_password" form={form} autoComplete="current-password" placeholder="Enter your current password"/><PasswordField label="New password" name="password" form={form} autoComplete="new-password" placeholder="Enter a new password"/><PasswordField label="Confirm new password" name="password_confirmation" form={form} autoComplete="new-password" placeholder="Confirm your new password"/><div className="flex justify-end border-t border-slate-100 pt-5"><button className="btn" disabled={form.processing}>{form.processing ? 'Updating…' : 'Update password'}</button></div></form><div className="max-w-xl rounded-2xl border border-red-100 bg-red-50/50 p-5"><h3 className="text-sm font-bold text-slate-900">{t('topbar.signOut')}</h3><p className="mt-1 text-sm leading-6 text-slate-600">End this browser session when you have finished using BBU ONLINE LEARNING.</p><button type="button" className="mt-4 rounded-xl border border-red-200 bg-white px-4 py-2 text-sm font-bold text-red-700 transition hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-400" onClick={() => router.post('/logout')}>{t('topbar.signOut')}</button></div></div>;
}

function NotificationsSounds() {
    const {t} = useTranslation();
    const sounds = useAppSounds();
    return <div className="mt-6 space-y-5"><div className="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-sky-100 bg-sky-50/65 p-5"><div><h3 className="text-sm font-bold text-slate-950">Application sounds</h3><p className="mt-1 max-w-xl text-sm leading-6 text-slate-600">Play short alerts for notifications, messages, meeting admission, participant changes, meeting end, and connection recovery.</p><p className="mt-2 text-xs font-medium text-slate-500">This preference is stored only in this browser.</p></div><button type="button" role="switch" aria-checked={sounds.enabled} onClick={() => sounds.setEnabled(!sounds.enabled)} className={`inline-flex min-w-24 items-center justify-center rounded-xl px-4 py-2.5 text-sm font-bold shadow-sm transition focus:outline-none focus:ring-2 focus:ring-sky-600 ${sounds.enabled ? 'bg-sky-700 text-white hover:bg-sky-800' : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50'}`}>{sounds.enabled ? 'Sounds on' : 'Sounds off'}</button></div><div className="rounded-xl border border-slate-200 bg-slate-50/70 p-4 text-sm leading-6 text-slate-600"><Icon name="bell" className="mr-2 inline h-4 w-4 text-sky-700"/>Browser sound permission and device volume still apply. BBU ONLINE LEARNING never uses sounds as a security or authorization signal.</div></div>;
}

function Appearance() {
    const {t} = useTranslation();

    return <div className="mt-6 space-y-5">
        <LanguagePreference/>
        <div className="rounded-2xl border border-slate-200 bg-slate-50/70 p-5"><h3 className="text-sm font-bold text-slate-950">{t('account.appearance.interfaceMode')}</h3><p className="mt-2 text-sm leading-6 text-slate-600">{t('account.appearance.interfaceHint')}</p><p className="mt-4 inline-flex rounded-lg bg-white px-3 py-2 text-xs font-bold text-slate-600 shadow-sm">{t('account.appearance.lightMode')}</p></div>
    </div>;
}

function LanguagePreference() {
    const {t, locale, setLocale, isSaving} = useTranslation();

    return <div className="rounded-2xl border border-slate-200 bg-slate-50/70 p-5">
        <h3 className="text-sm font-bold text-slate-950">{t('account.appearance.languageHeading')}</h3>
        <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-600">{t('account.appearance.languageDescription')}</p>
        <div className="mt-4 grid gap-3 sm:grid-cols-2">
            {languageOptions.map((option) => {
                const selected = option.value === locale;

                return <button
                    key={option.value}
                    type="button"
                    role="radio"
                    aria-checked={selected}
                    onClick={() => setLocale(option.value)}
                    className={`flex min-h-12 items-center gap-3 rounded-xl border px-4 py-3 text-left text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-sky-600 ${selected ? 'border-sky-300 bg-white text-sky-900 shadow-sm' : 'border-slate-200 bg-white/70 text-slate-700 hover:border-slate-300'}`}
                >
                    <span className={`grid h-5 w-5 shrink-0 place-items-center rounded-full border text-[10px] ${selected ? 'border-sky-700 bg-sky-700 text-white' : 'border-slate-300 text-transparent'}`} aria-hidden="true">✓</span>
                    <span lang={option.value} className="min-w-0 flex-1 truncate">{option.label}</span>
                    <span className="shrink-0 text-[10px] font-bold uppercase tracking-wider text-slate-400">{option.short}</span>
                </button>;
            })}
        </div>
        <p className="mt-3 text-xs leading-5 text-slate-500" role="status">{isSaving ? t('account.appearance.saving') : t('account.appearance.languageHint')}</p>
    </div>;
}

function ReadOnlyField({label, value, hint}) {
    return <div><p className="text-sm font-bold text-slate-800">{label}</p><p className="mt-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700">{value}</p><p className="mt-1.5 text-xs text-slate-500">{hint}</p></div>;
}

function PasswordField({label, name, form, autoComplete, placeholder}) {
    const [visible, setVisible] = useState(false);
    const error = form.errors[name];

    return <label className="block"><span className="text-sm font-bold text-slate-800">{label}</span><span className="relative mt-2 block"><input className={`h-12 w-full rounded-xl border bg-slate-50/80 px-4 pr-12 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:bg-white focus:ring-2 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500 ${error ? 'border-red-300 focus:border-red-500 focus:ring-red-100' : 'border-slate-300 focus:border-sky-600 focus:ring-sky-100'}`} type={visible ? 'text' : 'password'} value={form.data[name]} onChange={(event) => form.setData(name, event.target.value)} autoComplete={autoComplete} placeholder={placeholder} aria-invalid={Boolean(error)} aria-describedby={error ? `${name}-error` : undefined} disabled={form.processing} required/><button type="button" className="absolute right-2 top-1/2 inline-flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-lg text-slate-500 transition hover:bg-sky-50 hover:text-sky-700 focus:outline-none focus:ring-2 focus:ring-sky-600 disabled:cursor-not-allowed disabled:opacity-50" onClick={() => setVisible((current) => !current)} aria-label={visible ? 'Hide password' : 'Show password'} aria-pressed={visible} disabled={form.processing}><Icon name={visible ? 'eye-off' : 'eye'} className="h-5 w-5"/></button></span>{error && <p id={`${name}-error`} role="alert" className="mt-2 text-sm font-medium text-red-600">{error}</p>}</label>;
}
