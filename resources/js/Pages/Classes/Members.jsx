import React from 'react';
import {Head} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import ClassMemberDirectory from '../../Components/Classes/ClassMemberDirectory';

export default function Members({schoolClass, members, search}) {
    return <Layout><Head title={`${schoolClass.name} members`}/><div className="mx-auto max-w-7xl"><ClassMemberDirectory schoolClass={schoolClass} members={members} search={search}/></div></Layout>;
}
