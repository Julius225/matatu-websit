(() => {
    const endpoint = document.body.dataset.queueCountsUrl;
    if (!endpoint) {
        return;
    }

    const refreshCounts = async () => {
        try {
            const response = await fetch(endpoint, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            if (!response.ok) {
                throw new Error(`Queue count request failed with HTTP ${response.status}.`);
            }

            const result = await response.json();
            if (typeof result.counts !== 'object' || result.counts === null) {
                throw new Error('Queue count response has an invalid format.');
            }

            document.querySelectorAll('[data-queue-departure]').forEach((element) => {
                const departureId = element.dataset.queueDeparture;
                const count = result.counts[departureId] ?? 0;
                const capacity = element.dataset.queueCapacity;
                element.textContent = `${count} / ${capacity} passengers queued`;
            });
        } catch (error) {
            console.error('Unable to refresh passenger queue counts.', error);
        } finally {
            window.setTimeout(refreshCounts, 15000);
        }
    };

    window.setTimeout(refreshCounts, 15000);
})();
