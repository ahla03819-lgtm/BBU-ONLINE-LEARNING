import React from 'react';
import {Head} from '@inertiajs/react';
import ClassCard, {classCardTones} from '../../Components/Classes/ClassCard';
import Layout from '../../Layouts/AuthenticatedLayout';

export default function Index({classes}) {
    return <Layout><Head title="Class collaboration"/><div className="mx-auto max-w-7xl"><h1 className="mb-6 text-2xl font-bold">Class collaboration</h1>{classes.length ? <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{classes.map((schoolClass, index) => <ClassCard key={schoolClass.id} schoolClass={schoolClass} href={`/collaboration/classes/${schoolClass.id}`} tone={classCardTones[index % classCardTones.length]} actionLabel="Open collaboration" secondary="Collaboration workspace" count={schoolClass.channels_count} countLabel={schoolClass.channels_count === 1 ? 'channel' : 'channels'}/>)}</div> : <div className="card text-slate-500">No current collaboration spaces are available.</div>}</div></Layout>;
}
