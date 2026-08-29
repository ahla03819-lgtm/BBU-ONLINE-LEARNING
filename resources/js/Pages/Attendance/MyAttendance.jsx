import React, {useState} from 'react';
import {Head, router} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';

const statuses = ['present', 'absent', 'late', 'excused'];

export default function MyAttendance({records, summary, academicYears, filters}) {
    const [form, setForm] = useState({
        date_from: filters.date_from || '', date_to: filters.date_to || '', status: filters.status || '', academic_year_id: filters.academic_year_id || '',
    });
    const filter = (event) => {
        event.preventDefault();
        router.get('/my-attendance', Object.fromEntries(Object.entries(form).filter(([, value]) => value)), {preserveState: true, replace: true});
    };
    return <Layout><Head title="My Attendance"/><div className="mb-6"><h1 className="text-2xl font-bold">My Attendance</h1><p className="mt-1 text-slate-500">Your personal attendance history.</p></div><dl className="grid grid-cols-2 gap-3 sm:grid-cols-5"><Summary label="Total" value={summary.total}/><Summary label="Present" value={summary.present}/><Summary label="Absent" value={summary.absent}/><Summary label="Late" value={summary.late}/><Summary label="Excused" value={summary.excused}/></dl><form className="card mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" onSubmit={filter}><Field label="From"><input className="mt-1 block w-full rounded border-slate-300" type="date" value={form.date_from} onChange={(event) => setForm({...form, date_from: event.target.value})}/></Field><Field label="To"><input className="mt-1 block w-full rounded border-slate-300" type="date" value={form.date_to} onChange={(event) => setForm({...form, date_to: event.target.value})}/></Field><Field label="Status"><select className="mt-1 block w-full rounded border-slate-300" value={form.status} onChange={(event) => setForm({...form, status: event.target.value})}><option value="">All statuses</option>{statuses.map((status) => <option key={status} value={status}>{label(status)}</option>)}</select></Field><Field label="Academic year"><select className="mt-1 block w-full rounded border-slate-300" value={form.academic_year_id} onChange={(event) => setForm({...form, academic_year_id: event.target.value})}><option value="">All academic years</option>{academicYears.map((year) => <option key={year.id} value={year.id}>{year.name}</option>)}</select></Field><button className="self-end rounded border border-slate-300 px-4 py-2 text-sm font-medium">Filter</button></form><div className="mt-6 grid gap-3">{records.map((record, index) => <article className="card" key={`${record.attendance_date}-${index}`}><div className="flex flex-wrap items-start justify-between gap-3"><div><p className="text-sm text-slate-500">{record.school_class.grade_level?.name} · {record.school_class.academic_year?.name}</p><h2 className="font-semibold">{record.school_class.name} {record.school_class.section}</h2><p className="mt-1 text-sm">{record.attendance_date}{record.register_finalized ? ' · Finalized' : ' · Draft'}</p></div><span className={`rounded px-2 py-1 text-xs font-medium capitalize ${badge(record.status)}`}>{record.status}</span></div>{record.reason && <p className="mt-3 text-sm text-slate-600">Reason: {record.reason}</p>}</article>)}{records.length === 0 && <div className="card text-slate-500"><h2 className="font-medium text-slate-700">No attendance records found</h2><p className="mt-1">Your attendance records will appear here when available.</p></div>}</div></Layout>;
}

function Field({label: heading, children}) { return <label className="text-sm font-medium">{heading}{children}</label>; }
function Summary({label: heading, value}) { return <div className="card"><dt className="text-sm text-slate-500">{heading}</dt><dd className="mt-1 text-2xl font-semibold">{value}</dd></div>; }
function label(value) { return value.charAt(0).toUpperCase() + value.slice(1); }
function badge(status) { return {present: 'bg-emerald-100 text-emerald-800', absent: 'bg-red-100 text-red-800', late: 'bg-amber-100 text-amber-800', excused: 'bg-sky-100 text-sky-800'}[status] || 'bg-slate-100 text-slate-700'; }
