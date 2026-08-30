import React from 'react';
import {Head} from '@inertiajs/react';
import Layout from '../../../Layouts/AuthenticatedLayout';
import AssignmentForm from '../../../Components/Coursework/AssignmentForm';
import {AssignmentHeader} from './Create';

export default function Edit({schoolClass, classSubject, assignment}) { return <Layout><Head title="Edit assignment"/><AssignmentHeader schoolClass={schoolClass} classSubject={classSubject} eyebrow="Coursework authoring" title={`Edit ${assignment.title}`} backHref={`/school-classes/${schoolClass.id}/class-subjects/${classSubject.id}/assignments/${assignment.id}`}/><AssignmentForm method="patch" assignment={assignment} action={`/school-classes/${schoolClass.id}/class-subjects/${classSubject.id}/assignments/${assignment.id}`}/></Layout>; }
