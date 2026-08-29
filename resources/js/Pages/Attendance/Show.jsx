import React, {useState} from 'react';
import {Head, Link, router} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';

const statuses = ['present', 'absent', 'late', 'excused'];

export default function Show({schoolClass, register}) {
    const [pending, setPending] = useState(null);
    const [correctionFor, setCorrectionFor] = useState(null);
    const base = `/school-classes/${schoolClass.id}/attendance/${register.public_id}`;
    const save = (record, data) => {
        setPending(`save-${record.id}`);
        router.patch(`${base}/records/${record.id}`, data, {preserveScroll: true, onFinish: () => setPending(null)});
    };
    const finalize = () => {
        if (!confirm('Finalize this attendance register? Ordinary edits will no longer be available.')) return;
        setPending('finalize');
        router.post(`${base}/finalize`, {}, {preserveScroll: true, onFinish: () => setPending(null)});
    };
    const correct = (record, data) => {
        setPending(`correct-${record.id}`);
        router.post(`${base}/records/${record.id}/correct`, data, {preserveScroll: true, onFinish: () => { setPending(null); setCorrectionFor(null); }});
    };

    return <Layout>
        <Head title={`Attendance · ${schoolClass.name}`}/>
        <Link className="text-sm text-indigo-600" href="/attendance">← Attendance</Link>
        <div className="mt-4 flex flex-wrap items-start justify-between gap-4"><div><p className="text-sm text-slate-500">{schoolClass.grade_level?.name} · {schoolClass.academic_year?.name}</p><h1 className="text-2xl font-bold">{schoolClass.name} {schoolClass.section} · {register.attendance_date}</h1><p className="mt-1 text-sm capitalize text-slate-600">{register.status}{register.finalized_at && ` · Finalized ${new Date(register.finalized_at).toLocaleString()}${register.finalized_by ? ` by ${register.finalized_by.name}` : ''}`}</p></div><div className="flex gap-2">{register.can_finalize && <button className="rounded border border-red-300 px-4 py-2 text-sm font-medium text-red-700 disabled:opacity-50" disabled={pending === 'finalize'} onClick={finalize}>{pending === 'finalize' ? 'Finalizing…' : 'Finalize'}</button>}</div></div>
        <dl className="card mt-5 grid grid-cols-2 gap-3 sm:grid-cols-5"><Count label="Present" value={register.present_count}/><Count label="Absent" value={register.absent_count}/><Count label="Late" value={register.late_count}/><Count label="Excused" value={register.excused_count}/><Count label="Roster" value={register.total_roster_count}/></dl>
        <div className="card mt-6 overflow-x-auto"><table className="min-w-full text-left text-sm"><thead className="border-b text-slate-500"><tr><th className="px-3 py-2">Student</th><th className="px-3 py-2">Status</th><th className="px-3 py-2">Reason</th><th className="px-3 py-2">Actions</th></tr></thead><tbody>{register.records.map((record) => <RecordRow key={record.id} record={record} editable={register.can_record} saving={pending === `save-${record.id}`} correcting={pending === `correct-${record.id}`} correctionOpen={correctionFor === record.id} onSave={save} onCorrect={correct} onCorrectionToggle={() => setCorrectionFor(correctionFor === record.id ? null : record.id)}/>)}</tbody></table>{register.records.length === 0 && <p className="p-4 text-slate-500">The server snapshot contains no eligible students for this date.</p>}</div>
    </Layout>;
}

function RecordRow({record, editable, saving, correcting, correctionOpen, onSave, onCorrect, onCorrectionToggle}) {
    const [status, setStatus] = useState(record.status);
    const [reason, setReason] = useState(record.reason || '');
    const [correctionStatus, setCorrectionStatus] = useState(record.status);
    const [correctionReason, setCorrectionReason] = useState(record.reason || '');
    const [correctionExplanation, setCorrectionExplanation] = useState('');
    return <><tr className="border-b align-top"><td className="px-3 py-3"><p className="font-medium">{record.student.name}</p>{record.student.student_number && <p className="text-xs text-slate-500">{record.student.student_number}</p>}</td><td className="px-3 py-3">{editable ? <select className="rounded border-slate-300" value={status} onChange={(event) => setStatus(event.target.value)}>{statuses.map((value) => <option key={value} value={value}>{label(value)}</option>)}</select> : <span className="capitalize">{record.status}</span>}</td><td className="px-3 py-3">{editable ? <input className="w-full rounded border-slate-300" maxLength="1000" value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Optional reason"/> : (record.reason || <span className="text-slate-400">—</span>)}</td><td className="px-3 py-3"><div className="flex flex-wrap gap-2">{editable && <button className="rounded border px-3 py-1 text-xs disabled:opacity-50" disabled={saving} onClick={() => onSave(record, {status, reason: reason || null})}>{saving ? 'Saving…' : 'Save'}</button>}{record.can_correct && <button className="rounded border px-3 py-1 text-xs" onClick={onCorrectionToggle}>{correctionOpen ? 'Cancel correction' : 'Correct'}</button>}</div></td></tr>{correctionOpen && <tr className="border-b bg-amber-50"><td colSpan="4" className="p-4"><form className="grid gap-3 md:grid-cols-3" onSubmit={(event) => { event.preventDefault(); onCorrect(record, {status: correctionStatus, reason: correctionReason || null, correction_reason: correctionExplanation}); }}><label className="text-sm font-medium">Corrected status<select className="mt-1 block w-full rounded border-slate-300" value={correctionStatus} onChange={(event) => setCorrectionStatus(event.target.value)}>{statuses.map((value) => <option key={value} value={value}>{label(value)}</option>)}</select></label><label className="text-sm font-medium">Corrected reason<input className="mt-1 block w-full rounded border-slate-300" maxLength="1000" value={correctionReason} onChange={(event) => setCorrectionReason(event.target.value)}/></label><label className="text-sm font-medium">Why correction is needed<textarea className="mt-1 block w-full rounded border-slate-300" minLength="3" maxLength="1000" value={correctionExplanation} onChange={(event) => setCorrectionExplanation(event.target.value)} required/></label><button className="justify-self-start rounded border border-amber-500 px-3 py-2 text-sm font-medium text-amber-800 disabled:opacity-50" disabled={correcting}>Save correction</button></form></td></tr>}{record.revisions.length > 0 && <tr className="border-b bg-slate-50"><td colSpan="4" className="p-4"><h3 className="text-sm font-semibold">Correction history</h3><ul className="mt-2 space-y-2 text-xs text-slate-600">{record.revisions.map((revision, index) => <li key={index}><span className="font-medium capitalize">{revision.previous_status} → {revision.new_status}</span> · {revision.correction_reason} · {revision.corrected_by?.name || 'Unknown'} · {revision.corrected_at ? new Date(revision.corrected_at).toLocaleString() : ''}</li>)}</ul></td></tr>}</>;
}

function Count({label: heading, value}) { return <div><dt className="text-sm text-slate-500">{heading}</dt><dd className="text-lg font-semibold">{value}</dd></div>; }
function label(value) { return value.charAt(0).toUpperCase() + value.slice(1); }
