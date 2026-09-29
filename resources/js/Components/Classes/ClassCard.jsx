import React from 'react';
import {Link} from '@inertiajs/react';
import Icon from '../UI/Icon';
import {useTranslation} from '../../i18n/LocaleProvider';

const tones = {
    navy: 'from-[#082f63] to-[#075ca8]',
    blue: 'from-sky-700 to-[#2380c8]',
    amber: 'from-amber-500 to-orange-400',
    coral: 'from-rose-600 to-pink-500',
};

export const classCardTones = ['navy', 'blue', 'amber', 'coral'];

export default function ClassCard({schoolClass, href, tone = 'navy', actionLabel, secondary, count, countLabel}) {
    const {t} = useTranslation();
    const gradeLevel = schoolClass.gradeLevel || schoolClass.grade_level;
    const academicYear = schoolClass.academicYear || schoolClass.academic_year;
    const teacher = schoolClass.teacher;
    const resolvedSecondary = secondary || (teacher?.name ? `${t('classes.classTeacher')} · ${teacher.name}` : t('classes.notAssigned'));

    return <Link href={href || schoolClass.workspaceUrl} className="group overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-sky-200 hover:shadow-lg hover:shadow-sky-100/70 focus:outline-none focus:ring-2 focus:ring-sky-600">
        <div className={`relative min-h-28 overflow-hidden bg-gradient-to-br p-5 text-white ${tones[tone]}`}>
            <span className="absolute -right-7 -top-9 h-28 w-28 rounded-full bg-white/15"/>
            <span className="absolute bottom-3 right-4 text-white/80"><Icon name="school" className="h-11 w-11"/></span>
            <p className="relative text-xs font-bold uppercase tracking-wider text-white/80">{gradeLevel?.name || t('classes.classWorkspace')}</p>
            <h3 className="relative mt-2 pr-9 text-xl font-bold leading-tight">{schoolClass.name}{schoolClass.section ? ` ${schoolClass.section}` : ''}</h3>
        </div>
        <div className="p-5">
            <p className="text-sm font-semibold text-slate-800">{academicYear?.name || t('classes.academicYearNotAvailable')}</p>
            <p className="mt-1 text-xs text-slate-500">{resolvedSecondary}</p>
            <div className="mt-5 flex items-center justify-between border-t border-slate-100 pt-3">
                <span className="text-xs font-bold text-sky-700">{actionLabel}</span>
                {count !== null && count !== undefined && <span className="inline-flex items-center gap-1 text-xs font-semibold text-slate-500"><Icon name="users" className="h-3.5 w-3.5"/>{count}{countLabel ? ` ${countLabel}` : ''}</span>}
                <Icon name="arrow-right" className="h-4 w-4 text-sky-700 transition group-hover:translate-x-0.5"/>
            </div>
        </div>
    </Link>;
}
