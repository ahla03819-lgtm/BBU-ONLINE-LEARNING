import React from 'react';
import {Head, Link} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import MeetingForm from '../../Components/Meetings/MeetingForm';
import MeetingPageHeader from '../../Components/Meetings/MeetingPageHeader';

export default function Edit(props) { return <Layout><Head title="Edit meeting"/><MeetingPageHeader schoolClass={props.schoolClass} meeting={props.meeting} eyebrow="Live class planning" title="Edit scheduled meeting" description="Update the meeting details available through your existing authorization." backHref={`/school-classes/${props.schoolClass.id}/meetings/${props.meeting.uuid}`} backLabel="Meeting"/><MeetingForm {...props}/></Layout>; }
