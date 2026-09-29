import React from 'react';
import {Head} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import MeetingForm from '../../Components/Meetings/MeetingForm';
import MeetingPageHeader from '../../Components/Meetings/MeetingPageHeader';
import {useTranslation} from '../../i18n/LocaleProvider';

export default function Create(props) {
    const {t} = useTranslation();

    return <Layout><Head title={t('meetings.schedule')}/><MeetingPageHeader schoolClass={props.schoolClass} eyebrow={t('meetings.create.eyebrow')} title={t('meetings.create.title')} description={t('meetings.create.description')}/><MeetingForm {...props}/></Layout>;
}
