import React from 'react';
import {Head, Link} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import MeetingForm from '../../Components/Meetings/MeetingForm';

export default function Create(props) { return <Layout><Head title="Schedule meeting"/><Link className="text-sm text-indigo-600" href={`/school-classes/${props.schoolClass.id}/meetings`}>← Meetings</Link><h1 className="my-6 text-2xl font-bold">Schedule meeting for {props.schoolClass.name}</h1><MeetingForm {...props}/></Layout>; }
