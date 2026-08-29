import React, {useState} from 'react';
import {Head, Link, router, useForm} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';

const today = new Date().toISOString().slice(0, 10);

export default function Index({classes, registers, filters}) {
    const [classId, setClassId] = useState(filters.school_class_id || '');
    const [attendanceDate, setAttendanceDate] = useState(filters.attendance_date || '');
    const openableClasses = classes.filter((schoolClass) => schoolClass.can_open);
    const open = useForm({attendance_date: today});

    const applyFilters = (event) => {
        event.preventDefault();
        router.get('/attendance', {
            school_class_id: classId || undefined,
            attendance_date: attendanceDate || undefined,
        }, {preserveState: true, replace: true});
    };
    const openRegister = (event) => {
        event.preventDefault();
        if (!open.data.school_class_id) return;
        open.post(`/school-classes/${open.data.school_class_id}/attendance`);
    };

    return <Layout>
        <Head title="Attendance"/>
        <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div><h1 className="text-2xl font-bold">Attendance</h1><p className="mt-1 text-slate-500">Class registers available to you.</p></div>
            {openableClasses.length > 0 && <form className="card flex flex-wrap items-end gap-3" onSubmit={openRegister}>
                <label className="text-sm font-medium">Class<select className="mt-1 block rounded border-slate-300" value={open.data.school_class_id || ''} onChange={(event) => open.setData('school_class_id', event.target.value)} required><option value="">Select a class</option>{openableClasses.map((schoolClass) => <option key={schoolClass.id} value={schoolClass.id}>{schoolClass.name} {schoolClass.section}</option>)}</select></label>
                <label className="text-sm font-medium">Date<input className="mt-1 block rounded border-slate-300" type="date" value={open.data.attendance_date} onChange={(event) => open.setData('attendance_date', event.target.value)} required/></label>
                <button className="btn" disabled={open.processing}>Open attendance</button>
                {open.errors.attendance_date && <p className="w-full text-sm text-red-600">{open.errors.attendance_date}</p>}
            </form>}
        </div>

        <form className="card mb-6 flex flex-wrap items-end gap-3" onSubmit={applyFilters}>
            <label className="text-sm font-medium">Class<select className="mt-1 block rounded border-slate-300" value={classId} onChange={(event) => setClassId(event.target.value)}><option value="">All accessible classes</option>{classes.map((schoolClass) => <option key={schoolClass.id} value={schoolClass.id}>{schoolClass.name} {schoolClass.section}</option>)}</select></label>
            <label className="text-sm font-medium">Date<input className="mt-1 block rounded border-slate-300" type="date" value={attendanceDate} onChange={(event) => setAttendanceDate(event.target.value)}/></label>
            <button className="rounded border border-slate-300 px-4 py-2 text-sm font-medium">Filter</button>
        </form>

        <div className="grid gap-4">
            {registers.map((register) => <Link className="card block" key={register.public_id} href={`/school-classes/${register.school_class.id}/attendance/${register.public_id}`}><div className="flex flex-wrap items-start justify-between gap-3"><div><p className="text-sm text-slate-500">{register.school_class.grade_level?.name} · {register.school_class.academic_year?.name}</p><h2 className="text-lg font-semibold">{register.school_class.name} {register.school_class.section}</h2><p className="mt-1 text-sm">{register.attendance_date}</p></div><span className="rounded bg-slate-100 px-2 py-1 text-xs font-medium capitalize">{register.status}</span></div><dl className="mt-4 grid grid-cols-2 gap-2 text-sm sm:grid-cols-5"><Count label="Present" value={register.present_count}/><Count label="Absent" value={register.absent_count}/><Count label="Late" value={register.late_count}/><Count label="Excused" value={register.excused_count}/><Count label="Roster" value={register.total_roster_count}/></dl></Link>)}
            {registers.length === 0 && <div className="card text-slate-500"><h2 className="font-medium text-slate-700">No attendance registers found</h2><p className="mt-1">Open a register for an authorized class, or adjust the filters.</p></div>}
        </div>
    </Layout>;
}

function Count({label, value}) { return <div><dt className="text-slate-500">{label}</dt><dd className="font-semibold">{value}</dd></div>; }
