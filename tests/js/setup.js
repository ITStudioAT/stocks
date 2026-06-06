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
    value: vi.fn((query) => ({
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
        addEventListener: () => {},
        removeEventListener: () => {},
    },
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    Object.defineProperty(window, 'innerWidth', {
        configurable: true,
        writable: true,
        value: 1024,
    });
    Object.defineProperty(window.visualViewport, 'width', {
        configurable: true,
        writable: true,
        value: 1024,
    });
    Object.defineProperty(window, 'innerHeight', {
        configurable: true,
        writable: true,
        value: 768,
    });
    Object.defineProperty(window.visualViewport, 'height', {
        configurable: true,
        writable: true,
        value: 768,
    });
    document.head.innerHTML = '';
    document.body.innerHTML = '';
});
