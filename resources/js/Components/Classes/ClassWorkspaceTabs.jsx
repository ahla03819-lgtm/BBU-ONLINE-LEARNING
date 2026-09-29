import React from 'react';
import {Link} from '@inertiajs/react';
import Icon from '../UI/Icon';
import {useTranslation} from '../../i18n/LocaleProvider';

export default function ClassWorkspaceTabs({tabs}) {
    const {t} = useTranslation();
    return <nav aria-label={t('classes.classWorkspace')} className="overflow-x-auto rounded-2xl border border-slate-200 bg-white p-1.5 shadow-sm"><div className="flex min-w-max gap-1">{tabs.filter((tab) => tab.href).map((tab) => <Link key={tab.label} href={tab.href} className={`inline-flex min-h-10 items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-bold transition ${tab.active ? 'bg-sky-700 text-white shadow-md shadow-sky-200' : 'text-slate-600 hover:bg-sky-50 hover:text-sky-800'}`}><Icon name={tab.icon} className="h-4 w-4"/>{tab.label}</Link>)}</div></nav>;
}
