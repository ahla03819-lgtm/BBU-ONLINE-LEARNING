const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

export default function uploadRequest(url, fields, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.responseType = 'json';
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf());
        xhr.upload.onprogress = event => { if (event.lengthComputable) onProgress?.(Math.round((event.loaded / event.total) * 100)); };
        xhr.onload = () => {
            const data = xhr.response || {};
            if (xhr.status >= 200 && xhr.status < 300) resolve(data);
            else reject(new Error(data.message || Object.values(data.errors || {})[0]?.[0] || 'Upload failed.'));
        };
        xhr.onerror = () => reject(new Error('Upload connection failed.'));
        const form = new FormData();
        Object.entries(fields).forEach(([key, value]) => {
            if (value === null || value === undefined || value === '') return;
            if (key === 'attachments') value.forEach(item => { form.append('attachments[]', item.file); form.append('attachment_client_uuids[]', item.uuid); });
            else form.append(key, value);
        });
        xhr.send(form);
    });
}
