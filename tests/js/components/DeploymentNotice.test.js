import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { startDeploymentNotice } from '../../../public/deployment-notice.js';

let stop;

function createNotice(maintenancePage = false) {
    const dialog = document.createElement('dialog');
    dialog.dataset.state = maintenancePage ? 'maintenance' : 'idle';
    dialog.open = maintenancePage;
    dialog.show = vi.fn(() => { dialog.open = true; });
    dialog.showModal = vi.fn(() => { dialog.open = true; });
    dialog.close = vi.fn(() => { dialog.open = false; });
    dialog.innerHTML = `
        <h1 id="stocks-deployment-title"></h1>
        <p id="stocks-deployment-description"></p>
        <button data-deployment-reload hidden></button>
        <button data-deployment-dismiss hidden></button>
    `;
    document.body.append(dialog);
    return dialog;
}

function status(state) {
    return { status: 200, json: async () => ({ state }) };
}

describe('deployment notice', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        stop?.();
        stop = undefined;
        vi.useRealTimers();
    });

    it('warns without blocking, then opens a modal and waits for a manual reload', async () => {
        const dialog = createNotice();
        const fetchStatus = vi.fn()
            .mockResolvedValueOnce(status('scheduled'))
            .mockResolvedValueOnce(status('maintenance'))
            .mockResolvedValueOnce(status('completed'))
            .mockResolvedValueOnce({ status: 200 });
        const reload = vi.fn();
        stop = startDeploymentNotice(dialog, { fetchStatus, reload });
        await vi.advanceTimersByTimeAsync(0);

        expect(dialog.open).toBe(true);
        expect(dialog.dataset.state).toBe('scheduled');
        expect(dialog.showModal).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(10000);
        expect(dialog.showModal).toHaveBeenCalledOnce();
        expect(dialog.dataset.state).toBe('maintenance');
        expect(dialog.querySelector('[data-deployment-reload]').hidden).toBe(true);

        await vi.advanceTimersByTimeAsync(10000);
        expect(fetchStatus.mock.calls.at(-1)[0]).toBe('/up');
        expect(dialog.dataset.state).toBe('completed');
        expect(reload).not.toHaveBeenCalled();
        dialog.querySelector('[data-deployment-reload]').click();
        expect(reload).toHaveBeenCalledOnce();
    });

    it('keeps the maintenance page open until the health check succeeds', async () => {
        const dialog = createNotice(true);
        const fetchStatus = vi.fn()
            .mockResolvedValueOnce(status('failed'))
            .mockResolvedValueOnce(status('completed'))
            .mockResolvedValueOnce({ status: 503 })
            .mockResolvedValueOnce(status('completed'))
            .mockResolvedValueOnce({ status: 200 });
        const reload = vi.fn();

        stop = startDeploymentNotice(dialog, { fetchStatus, reload });
        await vi.advanceTimersByTimeAsync(0);
        expect(dialog.dataset.state).toBe('failed');

        await vi.advanceTimersByTimeAsync(10000);
        expect(dialog.dataset.state).toBe('unavailable');
        expect(dialog.querySelector('[data-deployment-reload]').hidden).toBe(true);

        await vi.advanceTimersByTimeAsync(10000);
        expect(dialog.dataset.state).toBe('completed');
        expect(dialog.querySelector('[data-deployment-reload]').hidden).toBe(false);
        expect(reload).not.toHaveBeenCalled();
    });

    it('clears an announcement when the deployment is cancelled before maintenance', async () => {
        const dialog = createNotice();
        const fetchStatus = vi.fn()
            .mockResolvedValueOnce(status('scheduled'))
            .mockResolvedValueOnce(status('idle'))
            .mockResolvedValueOnce(status('completed'));

        stop = startDeploymentNotice(dialog, { fetchStatus });
        await vi.advanceTimersByTimeAsync(20000);

        expect(dialog.dataset.state).toBe('idle');
        expect(dialog.open).toBe(false);
        expect(fetchStatus.mock.calls.map(([path]) => path)).toEqual(Array(3).fill('/deployment-status.php'));
    });
});
