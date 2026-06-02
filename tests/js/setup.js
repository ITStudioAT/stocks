import { afterEach, vi } from 'vitest';

global.ResizeObserver = class ResizeObserver {
    observe() {}

    unobserve() {}

    disconnect() {}
};

global.IntersectionObserver = class IntersectionObserver {
    observe() {}

    unobserve() {}

    disconnect() {}
};

Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: vi.fn().mockImplementation((query) => ({
        matches: false,
        media: query,
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
    })),
});

Object.defineProperty(window, 'visualViewport', {
    writable: true,
    value: {
        height: 768,
        offsetLeft: 0,
        offsetTop: 0,
        width: 1024,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    },
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    document.head.innerHTML = '';
    document.body.innerHTML = '';
});
