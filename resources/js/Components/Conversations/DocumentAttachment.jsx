import React from 'react';
import Icon from '../UI/Icon';

const documentExtensions = new Set(['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv']);

const extensionOf = item => {
    const name = String(item?.name || '');
    const extension = name.split('.').pop()?.toLowerCase();

    return documentExtensions.has(extension) ? extension : null;
};

export const isDocumentAttachment = item => {
    const mime = String(item?.mime_type || item?.type || '').toLowerCase();

    return Boolean(extensionOf(item)) || mime === 'application/pdf' || mime.startsWith('application/msword') || mime.includes('officedocument') || mime.includes('ms-excel') || mime.includes('ms-powerpoint') || mime.startsWith('text/') || mime === 'application/csv';
};

export const formatFileSize = value => {
    const bytes = Number(value);
    if (!Number.isFinite(bytes) || bytes < 1) return '0 KB';
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;

    return `${(bytes / (1024 * 1024)).toFixed(bytes >= 10 * 1024 * 1024 ? 0 : 1)} MB`;
};

const details = item => {
    const extension = extensionOf(item) || 'file';
    if (extension === 'pdf') return {label: 'PDF document', tone: 'bg-rose-50 text-rose-700 ring-rose-100'};
    if (['doc', 'docx'].includes(extension)) return {label: 'Word document', tone: 'bg-blue-50 text-blue-700 ring-blue-100'};
    if (['xls', 'xlsx', 'csv'].includes(extension)) return {label: 'Spreadsheet', tone: 'bg-emerald-50 text-emerald-700 ring-emerald-100'};
    if (['ppt', 'pptx'].includes(extension)) return {label: 'Presentation', tone: 'bg-amber-50 text-amber-700 ring-amber-100'};
    return {label: 'Text document', tone: 'bg-slate-100 text-slate-700 ring-slate-200'};
};

function DocumentMark({item, compact = false}) {
    const document = details(item);

    return <span className={`inline-flex shrink-0 items-center justify-center rounded-xl ring-1 ${compact ? 'h-9 w-9 text-[9px]' : 'h-12 w-12 text-[10px]'} font-extrabold tracking-wide ${document.tone}`} aria-hidden="true"><Icon name="file" className={compact ? 'h-4 w-4' : 'h-5 w-5'}/><span className="sr-only">{document.label}</span></span>;
}

export function DocumentAttachmentPreview({file, onRemove}) {
    const document = details(file);

    return <div className="flex min-w-0 items-center gap-3 rounded-2xl border border-blue-100 bg-blue-50/70 px-3 py-2.5 shadow-sm">
        <DocumentMark item={file}/>
        <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-bold text-slate-800" title={file.name}>{file.name}</p>
            <p className="mt-0.5 text-xs text-slate-500">{document.label} · {formatFileSize(file.size)}</p>
        </div>
        <button type="button" onClick={onRemove} className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-white hover:text-rose-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700" aria-label={`Remove ${file.name}`}><Icon name="x" className="h-4 w-4"/></button>
    </div>;
}

export function DocumentMessageBubble({attachment, isOutgoing, timestamp, read}) {
    const document = details(attachment);
    const tone = isOutgoing ? 'border-white/20 bg-white/10 text-white hover:bg-white/15' : 'border-slate-200 bg-slate-50 text-slate-800 hover:border-blue-200 hover:bg-blue-50/50';

    return <a href={attachment.url} className={`mt-2 flex min-w-0 max-w-full items-center gap-3 rounded-2xl border px-3 py-2.5 text-left shadow-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700 ${tone}`} aria-label={`Download ${attachment.name}`}>
        <DocumentMark item={attachment}/>
        <span className="min-w-0 flex-1">
            <span className="block truncate text-sm font-bold" title={attachment.name}>{attachment.name}</span>
            <span className={`mt-0.5 flex items-center gap-1.5 text-xs ${isOutgoing ? 'text-white/75' : 'text-slate-500'}`}><span>{document.label}</span><span aria-hidden="true">·</span><span>{formatFileSize(attachment.size_bytes)}</span></span>
            <span className={`mt-1 flex items-center gap-1 text-[11px] ${isOutgoing ? 'text-white/70' : 'text-slate-400'}`}><Icon name="download" className="h-3.5 w-3.5"/>Download <span className="ml-auto">{timestamp}{isOutgoing ? (read ? ' · ✓✓' : ' · ✓') : ''}</span></span>
        </span>
    </a>;
}
