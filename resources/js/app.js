const realtimeSnapshotUrl = '/realtime/snapshot';
const realtimePollIntervalMs = 2000;

const trackedEntities = ['project', 'feature', 'task', 'document', 'issue'];

let previousSnapshot = null;
let pollTimerId = null;
let pollInFlight = false;

const normalizeSnapshot = (snapshot) => {
    if (snapshot === null || typeof snapshot !== 'object') {
        return null;
    }

    const normalized = {};

    trackedEntities.forEach((entity) => {
        normalized[entity] = Number(snapshot[entity] ?? 0);
    });

    return normalized;
};

const refreshLivewireComponents = () => {
    if (typeof window.Livewire?.all !== 'function') {
        return;
    }

    window.Livewire.all().forEach(($wire) => {
        if (typeof $wire.$refresh === 'function') {
            $wire.$refresh();
        }
    });
};

const applyRealtimeChanges = (nextSnapshot) => {
    if (previousSnapshot === null) {
        previousSnapshot = nextSnapshot;

        return;
    }

    const changedEntities = trackedEntities.filter((entity) => {
        return nextSnapshot[entity] !== previousSnapshot[entity];
    });

    if (changedEntities.length === 0) {
        return;
    }

    window.dispatchEvent(new CustomEvent('realtime-entities-updated', {
        detail: { entities: changedEntities },
    }));

    refreshLivewireComponents();

    previousSnapshot = nextSnapshot;
};

const pollRealtimeSnapshot = async () => {
    if (pollInFlight) {
        return;
    }

    pollInFlight = true;

    try {
        const response = await fetch(realtimeSnapshotUrl, {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            return;
        }

        const snapshot = normalizeSnapshot(await response.json());

        if (snapshot === null) {
            return;
        }

        applyRealtimeChanges(snapshot);
    } catch (_error) {
        // Ignore transient polling errors.
    } finally {
        pollInFlight = false;
    }
};

const startRealtimePolling = () => {
    if (typeof window.Livewire === 'undefined') {
        return;
    }

    if (pollTimerId !== null) {
        return;
    }

    pollRealtimeSnapshot();

    pollTimerId = window.setInterval(() => {
        if (document.hidden) {
            return;
        }

        pollRealtimeSnapshot();
    }, realtimePollIntervalMs);
};

const stopRealtimePolling = () => {
    if (pollTimerId === null) {
        return;
    }

    window.clearInterval(pollTimerId);
    pollTimerId = null;
};

document.addEventListener('livewire:init', startRealtimePolling);

document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        stopRealtimePolling();

        return;
    }

    startRealtimePolling();
    pollRealtimeSnapshot();
});
