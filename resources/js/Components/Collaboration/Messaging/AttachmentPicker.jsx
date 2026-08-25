import React, {useState} from 'react';

const allowed = '.jpg,.jpeg,.png,.webp,.pdf,.docx,.xlsx,.pptx,.txt,.csv';
const extensions = new Set(allowed.split(','));
const size = bytes => `${(bytes / 1024 / 1024).toFixed(1)} MB`;

export default function AttachmentPicker({attachments, setAttachments, error}) {
    const [localError, setLocalError] = useState(null);
    const add = event => {
        const selected = [...event.target.files];
        const next = [...attachments];
        let validationError = null;
        for (const file of selected) {
            const extension = `.${file.name.split('.').pop()?.toLowerCase()}`;
            if (!extensions.has(extension)) { validationError = `${file.name} is not a supported file type.`; continue; }
            if (file.size < 1 || file.size > 10 * 1024 * 1024) { validationError = `${file.name} must be between 1 byte and 10 MB.`; continue; }
            if (next.length >= 5) { validationError = 'A message can contain at most 5 files.'; break; }
            if (next.reduce((total, item) => total + item.file.size, 0) + file.size > 25 * 1024 * 1024) { validationError = 'Combined attachments may not exceed 25 MB.'; continue; }
            const previewable = ['.jpg', '.jpeg', '.png', '.webp'].includes(extension) && file.type.startsWith('image/');
            next.push({file, uuid: crypto.randomUUID(), previewUrl: previewable ? URL.createObjectURL(file) : null});
        }
        setLocalError(validationError); setAttachments(next); event.target.value = '';
    };
    const remove = index => setAttachments(attachments.filter((item, position) => { if (position === index && item.previewUrl) URL.revokeObjectURL(item.previewUrl); return position !== index; }));
    const total = attachments.reduce((sum, item) => sum + item.file.size, 0);
    return <div className="mt-2 rounded-lg border border-dashed p-3"><label className="cursor-pointer text-sm font-medium text-indigo-600">Attach files<input className="hidden" type="file" accept={allowed} multiple onChange={add}/></label>
        <span className="ml-2 text-xs text-slate-400">Up to 5 files, 10 MB each, 25 MB total</span>
        {attachments.map((item, index) => <div key={item.uuid} className="mt-2 flex items-center justify-between text-xs"><span className="truncate">{item.file.name} · {size(item.file.size)}</span><button type="button" onClick={() => remove(index)}>Remove</button></div>)}
        <div className="mt-2 text-xs text-slate-400">{attachments.length}/5 · {size(total)} combined</div>{(localError || error) && <p className="text-xs text-red-600">{localError || error}</p>}
    </div>;
}
