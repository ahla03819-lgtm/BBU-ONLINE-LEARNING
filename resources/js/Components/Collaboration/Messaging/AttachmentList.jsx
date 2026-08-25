import React from 'react';

const size = bytes => bytes < 1024 * 1024 ? `${Math.ceil(bytes / 1024)} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
export default function AttachmentList({attachments = []}) {
    return <div className="mt-2 grid gap-2 sm:grid-cols-2">{attachments.map(attachment => <div key={attachment.id || attachment.uuid} className="rounded-lg border bg-white p-2 text-xs">
        {attachment.previewUrl && <img src={attachment.previewUrl} className="mb-2 max-h-36 rounded object-contain" alt=""/>}
        {attachment.preview_url && <a href={attachment.preview_url} target="_blank" rel="noreferrer"><img src={attachment.preview_url} className="mb-2 max-h-36 rounded object-contain" alt=""/></a>}
        <div className="truncate font-medium">{attachment.display_name || attachment.file?.name}</div><div className="text-slate-400">{size(attachment.size_bytes || attachment.file?.size || 0)}</div>
        {attachment.download_url && <a className="text-indigo-600" href={attachment.download_url}>Download</a>}
    </div>)}</div>;
}
