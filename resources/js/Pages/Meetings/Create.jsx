import React from 'react';
import {Head, Link} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import MeetingForm from '../../Components/Meetings/MeetingForm';
import MeetingPageHeader from '../../Components/Meetings/MeetingPageHeader';

export default function Create(props) { return <Layout><Head title="Schedule meeting"/><MeetingPageHeader schoolClass={props.schoolClass} eyebrow="Live class planning" title="Schedule meeting" description="Set up a live class using the current authorized subject and host options."/><MeetingForm {...props}/></Layout>; }
