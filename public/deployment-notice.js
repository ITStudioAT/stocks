const messages = {
    scheduled: ['Aktualisierung angekündigt', 'Stocks wird in Kürze aktualisiert. Bitte speichern Sie jetzt Ihre Eingaben.'],
    maintenance: ['Stocks wird aktualisiert', 'Bitte lassen Sie diese Seite geöffnet. Sobald Stocks wieder verfügbar ist, können Sie die Seite neu laden.'],
    failed: ['Aktualisierung verzögert', 'Wir prüfen weiter, wann Stocks wieder verfügbar ist. Bitte lassen Sie diese Seite geöffnet.'],
    unavailable: ['Verbindung unterbrochen', 'Wir prüfen die Verfügbarkeit von Stocks automatisch weiter.'],
    completed: ['Stocks ist wieder verfügbar', 'Sie können die Seite jetzt neu laden.'],
};

export function startDeploymentNotice(dialog, { fetchStatus = window.fetch.bind(window), reload = () => window.location.reload() } = {}) {
    const heading = dialog.querySelector('#stocks-deployment-title');
    const description = dialog.querySelector('#stocks-deployment-description');
    const reloadButton = dialog.querySelector('[data-deployment-reload]');
    const dismissButton = dialog.querySelector('[data-deployment-dismiss]');
    const maintenancePage = dialog.dataset.state === 'maintenance';
    let observedDeployment = maintenancePage;
    let observedMaintenance = maintenancePage;
    let modal = false;
    let stopped = false;
    let timer;

    function display(state) {
        if (stopped || dialog.dataset.state === state) {
            return;
        }

        dialog.dataset.state = state;

        if (state === 'idle') {
            dialog.close();
            return;
        }

        [heading.textContent, description.textContent] = messages[state];
        reloadButton.hidden = state !== 'completed';
        dismissButton.hidden = state !== 'completed' || maintenancePage;

        const shouldUseModal = observedMaintenance && !maintenancePage;
        if (dialog.open && modal !== shouldUseModal) {
            dialog.close();
        }

        modal = shouldUseModal;
        if (!dialog.open) {
            if (modal) {
                dialog.showModal();
            } else if (state === 'scheduled') {
                // An announcement should leave focus on the current input.
                dialog.open = true;
            } else {
                dialog.show();
            }
        }
    }

    async function request(path) {
        const response = await fetchStatus(path, {
            cache: 'no-store',
            redirect: 'error',
            signal: AbortSignal.timeout(5000),
        });

        if (response.status !== 200) {
            throw new Error('Stocks availability could not be confirmed.');
        }

        return response;
    }

    async function poll() {
        try {
            const response = await request('/deployment-status.php');
            const { state } = await response.json();

            if (state === 'scheduled' || state === 'maintenance' || state === 'failed') {
                observedDeployment = true;
                observedMaintenance = observedMaintenance || state !== 'scheduled';
                display(state);
            } else if (observedDeployment && (state === 'completed' || (state === 'idle' && observedMaintenance))) {
                await request('/up');
                observedDeployment = false;
                display('completed');
                observedMaintenance = maintenancePage;
            } else if (state === 'idle' && observedDeployment) {
                observedDeployment = false;
                display('idle');
            }
        } catch {
            if (observedDeployment) {
                display('unavailable');
            }
        } finally {
            if (!stopped) {
                timer = window.setTimeout(poll, 10000);
            }
        }
    }

    dialog.addEventListener('cancel', (event) => {
        if (dialog.dataset.state !== 'completed') {
            event.preventDefault();
        }
    });
    reloadButton.addEventListener('click', reload);
    dismissButton.addEventListener('click', () => dialog.close());

    void poll();

    return () => {
        stopped = true;
        window.clearTimeout(timer);
    };
}

const notice = document.querySelector('[data-deployment-notice]');
if (notice) {
    startDeploymentNotice(notice);
}
