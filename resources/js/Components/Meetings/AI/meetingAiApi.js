const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

async function api(request, url, {method = 'GET', body, query = null} = {}) {
    const separator = url.includes('?') ? '&' : '?';
    const queryString = query ? `${separator}${new URLSearchParams(query).toString()}` : '';
    const response = await fetch(`${url}${queryString}`, {
        method,
        headers: {
            'X-CSRF-TOKEN': csrf(),
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
            ...(body === undefined ? {} : {'Content-Type': 'application/json'}),
        },
        ...(body === undefined ? {} : {body: JSON.stringify(body)}),
        ...(request === 'GET' ? {cache: 'no-store'} : {}),
    });

    if (!response.ok) {
        let message = 'Request failed.';
        try {
            const payload = await response.json();
            message = payload.message || payload.errors?.meeting?.[0] || message;
        } catch {
            // keep default message for non-JSON error responses
        }
        const error = new Error(message);
        error.status = response.status;
        throw error;
    }

    if (response.status === 204) return null;
    return response.json();
}

export function getTranscript(schoolClass, meeting) {
    return api('GET', `/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/ai/transcript`);
}

export function getNotes(schoolClass, meeting, language = 'en') {
    return api('GET', `/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/ai/notes`, {query: {language}});
}

export function requestNotes(schoolClass, meeting, language = 'en') {
    return api('POST', `/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/ai/notes`, {
        body: {},
        query: {language},
    });
}

export function getSummary(schoolClass, meeting, language = 'en') {
    return api('GET', `/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/ai/summary`, {query: {language}});
}

export function requestSummary(schoolClass, meeting, language = 'en') {
    return api('POST', `/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/ai/summary`, {
        body: {},
        query: {language},
    });
}
