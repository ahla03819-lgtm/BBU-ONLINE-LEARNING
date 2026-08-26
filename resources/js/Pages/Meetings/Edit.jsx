import React from 'react';
import {Head, Link} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import MeetingForm from '../../Components/Meetings/MeetingForm';

export default function Edit(props) { return <Layout><Head title="Edit meeting"/><Link className="text-sm text-indigo-600" href={`/school-classes/${props.schoolClass.id}/meetings/${props.meeting.uuid}`}>← Meeting</Link><h1 className="my-6 text-2xl font-bold">Edit scheduled meeting</h1><MeetingForm {...props}/></Layout>; }
