import React from 'react';
import {Head} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import MeetingForm from '../../Components/Meetings/MeetingForm';
import MeetingPageHeader from '../../Components/Meetings/MeetingPageHeader';
import {useTranslation} from '../../i18n/LocaleProvider';

export default function Edit(props) {
    const {t} = useTranslation();

    return <Layout><Head title={t('meetings.editMeeting')}/><MeetingPageHeader schoolClass={props.schoolClass} meeting={props.meeting} eyebrow={t('meetings.edit.eyebrow')} title={t('meetings.edit.title')} description={t('meetings.edit.description')} backHref={`/school-classes/${props.schoolClass.id}/meetings/${props.meeting.uuid}`} backLabel={t('meetings.edit.backLabel')}/><MeetingForm {...props}/></Layout>;
}
