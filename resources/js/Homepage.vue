<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';

const adminUrl = '/admin/login';

// ── Chart geometry ────────────────────────────────────────────────────────────
// viewBox is 1440 x 560. Two series are procedurally generated as a placeholder
// for real portfolio time-series: green trends upward, red trends downward.
const VIEW_WIDTH = 1440;
const VIEW_HEIGHT = 560;
const POINT_COUNT = 9;

/**
 * Build a smooth path string from points using a Catmull-Rom spline converted to
 * cubic-bezier segments (uniform, tension 1/6).
 *
 * @param {{x: number, y: number}[]} points
 * @returns {string}
 */
function buildSmoothPath(points) {
    if (points.length === 0) {
        return '';
    }

    let d = `M ${points[0].x.toFixed(2)} ${points[0].y.toFixed(2)}`;

    for (let i = 0; i < points.length - 1; i++) {
        const p0 = points[i - 1] ?? points[i];
        const p1 = points[i];
        const p2 = points[i + 1];
        const p3 = points[i + 2] ?? p2;

        const cp1x = p1.x + (p2.x - p0.x) / 6;
        const cp1y = p1.y + (p2.y - p0.y) / 6;
        const cp2x = p2.x - (p3.x - p1.x) / 6;
        const cp2y = p2.y - (p3.y - p1.y) / 6;

        d += ` C ${cp1x.toFixed(2)} ${cp1y.toFixed(2)}, ${cp2x.toFixed(2)} ${cp2y.toFixed(2)}, ${p2.x.toFixed(2)} ${p2.y.toFixed(2)}`;
    }

    return d;
}

/**
 * @param {{x: number, y: number}[]} points
 * @returns {string}
 */
function buildAreaPath(points) {
    const line = buildSmoothPath(points);
    const last = points[points.length - 1];

    return `${line} L ${last.x.toFixed(2)} ${VIEW_HEIGHT} L ${points[0].x.toFixed(2)} ${VIEW_HEIGHT} Z`;
}

/**
 * @param {number} startY
 * @param {number} endY
 * @param {number} phase
 * @param {number} amplitude
 * @returns {{x: number, y: number}[]}
 */
function generateSeries(startY, endY, phase, amplitude) {
    const points = [];

    for (let i = 0; i < POINT_COUNT; i++) {
        const t = i / (POINT_COUNT - 1);
        const trend = startY + (endY - startY) * t;
        const wiggle = Math.sin(i * phase) * amplitude;

        points.push({ x: t * VIEW_WIDTH, y: trend + wiggle });
    }

    return points;
}

const greenPoints = generateSeries(430, 150, 1.7, 18);
const redPoints = generateSeries(150, 430, 2.1, 16);

const greenLine = buildSmoothPath(greenPoints);
const redLine = buildSmoothPath(redPoints);
const greenArea = buildAreaPath(greenPoints);
const redArea = buildAreaPath(redPoints);
const greenEnd = greenPoints[greenPoints.length - 1];
const redEnd = redPoints[redPoints.length - 1];

const gridLines = Array.from({ length: 5 }, (_, i) => ((i + 1) / 6) * VIEW_HEIGHT);

const reducedMotion = typeof window !== 'undefined'
    && typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// ── Live indices ticker ───────────────────────────────────────────────────────
const indices = ref([]);
const updatedSymbols = ref(new Set());
let pollTimer = null;
let flashTimer = null;

async function fetchIndices() {
    try {
        const res = await fetch('/indices');
        if (!res.ok) return;
        const data = await res.json();
        const next = data.indexes ?? [];

        if (indices.value.length > 0) {
            const changed = new Set(
                next
                    .filter(n => {
                        const prev = indices.value.find(o => o.symbol === n.symbol);
                        return prev != null && prev.latest_price !== n.latest_price;
                    })
                    .map(n => n.symbol),
            );
            if (changed.size > 0) {
                if (flashTimer) clearTimeout(flashTimer);
                updatedSymbols.value = changed;
                flashTimer = setTimeout(() => { updatedSymbols.value = new Set(); }, 2200);
            }
        }

        indices.value = next;
    } catch {
        // silent — ticker stays hidden on failure
    }
}

const tickerLoopItems = computed(() => {
    if (indices.value.length === 0) {
        return [];
    }

    const repetitions = Math.max(3, Math.ceil(8 / indices.value.length));

    return Array.from({ length: repetitions }, () => indices.value).flat();
});
const tickerItems = computed(() => [...tickerLoopItems.value, ...tickerLoopItems.value]);
const tickerDuration = computed(() => `${Math.max(tickerLoopItems.value.length * 20, 180)}s`);

const marketMood = computed(() => {
    if (!indices.value.length) return 0;
    const nums = indices.value
        .map(i => Number(i.latest_price_change_pct ?? 0))
        .filter(n => !Number.isNaN(n));
    if (!nums.length) return 0;
    const avg = nums.reduce((s, n) => s + n, 0) / nums.length;
    return Math.max(-3, Math.min(3, avg));
});

// ── Eyebrow corruption ───────────────────────────────────────────────────────
const eyebrowCorrupted = ref(false);
let eyebrowTimer = null;

function scheduleEyebrowGlitch() {
    eyebrowTimer = setTimeout(() => {
        eyebrowCorrupted.value = true;
        setTimeout(() => {
            eyebrowCorrupted.value = false;
            scheduleEyebrowGlitch();
        }, 140);
    }, 6000 + Math.random() * 9000);
}

const moodBlobGreenBg = computed(() => {
    const opacity = Math.max(0.06, Math.min(0.80, 0.38 + marketMood.value * 0.13));
    return `rgba(46, 204, 113, ${opacity.toFixed(3)})`;
});

const moodBlobRedBg = computed(() => {
    const opacity = Math.max(0.06, Math.min(0.72, 0.32 - marketMood.value * 0.11));
    return `rgba(231, 76, 60, ${opacity.toFixed(3)})`;
});

function formatTickerPrice(item) {
    if (item.latest_price == null) return '–';
    const num = Number(item.latest_price);
    if (Number.isNaN(num)) return '–';
    const formatted = num.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 4,
    });
    return item.currency && item.currency !== 'EUR' ? `${formatted} ${item.currency}` : formatted;
}

function formatTickerChange(item) {
    if (item.latest_price_change_pct == null) return null;
    const num = Number(item.latest_price_change_pct);
    if (Number.isNaN(num)) return null;
    const sign = num > 0 ? '+' : '';
    const arrow = num > 0 ? '▲' : num < 0 ? '▼' : '▶';
    return `${arrow} ${sign}${num.toFixed(2)}%`;
}

function tickerChangeClass(item) {
    const num = Number(item.latest_price_change_pct ?? 0);
    if (num > 0) return 'change-up';
    if (num < 0) return 'change-down';
    return 'change-flat';
}

// ── Mouse-driven 3D parallax ─────────────────────────────────────────────────
let rafId = null;
let lerpMx = 0;
let lerpMy = 0;
let rawMx = 0;
let rawMy = 0;

function onMouseMove(e) {
    rawMx = e.clientX / window.innerWidth - 0.5;
    rawMy = e.clientY / window.innerHeight - 0.5;
}

function onMouseLeave() {
    rawMx = 0;
    rawMy = 0;
}

function tickParallax() {
    lerpMx += (rawMx - lerpMx) * 0.055;
    lerpMy += (rawMy - lerpMy) * 0.055;
    document.documentElement.style.setProperty('--mx', lerpMx.toFixed(4));
    document.documentElement.style.setProperty('--my', lerpMy.toFixed(4));
    rafId = requestAnimationFrame(tickParallax);
}

onMounted(() => {
    fetchIndices();
    if (!reducedMotion) {
        pollTimer = window.setInterval(fetchIndices, 60_000);
        scheduleEyebrowGlitch();
        window.addEventListener('mousemove', onMouseMove);
        window.addEventListener('mouseleave', onMouseLeave);
        if (typeof requestAnimationFrame !== 'undefined') {
            rafId = requestAnimationFrame(tickParallax);
        }
    }
});

onUnmounted(() => {
    if (pollTimer) window.clearInterval(pollTimer);
    if (flashTimer) clearTimeout(flashTimer);
    if (eyebrowTimer) clearTimeout(eyebrowTimer);
    window.removeEventListener('mousemove', onMouseMove);
    window.removeEventListener('mouseleave', onMouseLeave);
    if (rafId) cancelAnimationFrame(rafId);
});
</script>

<template>
    <div class="stage" :class="{ 'is-still': reducedMotion }">
        <!-- 3D background plane (rotates with mouse) -->
        <div class="depth-bg" aria-hidden="true">
            <div class="bg-base"></div>
            <div class="blob blob-green" :style="{ background: moodBlobGreenBg }"></div>
            <div class="blob blob-red" :style="{ background: moodBlobRedBg }"></div>
            <div class="blob blob-blue"></div>
            <div class="blob blob-violet"></div>
        </div>
        <div class="grain" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">
                <filter id="grain-noise">
                    <feTurbulence type="fractalNoise" baseFrequency="0.9" numOctaves="2" stitchTiles="stitch" />
                </filter>
                <rect width="100%" height="100%" filter="url(#grain-noise)" />
            </svg>
        </div>
        <div class="haze" aria-hidden="true"></div>
        <div class="sweep" aria-hidden="true"></div>
        <div class="sweep sweep-2" aria-hidden="true"></div>
        <div class="glitch-bar glitch-bar-a" aria-hidden="true"></div>
        <div class="glitch-bar glitch-bar-b" aria-hidden="true"></div>
        <div class="glitch-bar glitch-bar-c" aria-hidden="true"></div>
        <div class="scan-lines" aria-hidden="true"></div>

        <!-- Header -->
        <header class="header">
            <a class="wordmark" href="/" aria-label="Stocks home">Stocks<span class="wordmark-dot">.</span></a>
            <a class="admin-pill" :href="adminUrl">Admin</a>
        </header>

        <!-- Hero -->
        <section class="hero">
            <p class="eyebrow">
                <span class="pulse-dot" aria-hidden="true"></span>
                <span v-if="eyebrowCorrupted" aria-hidden="true">T̸O̷T̴A̵L̶ H̷O̴L̸D̷I̵N̷G̸S ·̸ L̵I̷V̸E̴</span>
                <span v-else>Total holdings · Live</span>
            </p>
            <h1 class="headline">A quiet place to watch <span class="headline-your">your</span> money grow.</h1>
        </section>

        <!-- Indices ticker -->
        <div v-if="indices.length" class="ticker-wrap" aria-label="Live market indices">
            <div class="ticker-overflow">
                <div
                    class="ticker-track"
                    :style="{ '--ticker-duration': tickerDuration }"
                >
                    <span
                        v-for="(item, i) in tickerItems"
                        :key="`${item.symbol}-${i}`"
                        class="ticker-item"
                        :class="{ 'is-updated': updatedSymbols.has(item.symbol) }"
                    >
                        <span class="ticker-symbol">{{ item.symbol }}</span>
                        <span v-if="item.country" class="ticker-country">{{ item.country }}</span>
                        <span class="ticker-price">{{ formatTickerPrice(item) }}</span>
                        <span
                            v-if="formatTickerChange(item)"
                            class="ticker-change"
                            :class="tickerChangeClass(item)"
                        >{{ formatTickerChange(item) }}</span>
                        <span class="ticker-div" aria-hidden="true">·</span>
                    </span>
                </div>
            </div>
        </div>

        <!-- Chart -->
        <svg
            class="chart"
            viewBox="0 0 1440 560"
            preserveAspectRatio="none"
            aria-hidden="true"
        >
            <defs>
                <linearGradient id="green-fill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="rgba(46,204,113,.22)" />
                    <stop offset="100%" stop-color="rgba(46,204,113,0)" />
                </linearGradient>
                <linearGradient id="red-fill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="rgba(231,76,60,.16)" />
                    <stop offset="100%" stop-color="rgba(231,76,60,0)" />
                </linearGradient>
            </defs>

            <line
                v-for="(y, i) in gridLines"
                :key="i"
                class="grid-line"
                x1="0"
                :y1="y"
                x2="1440"
                :y2="y"
            />

            <path class="area area-green" :d="greenArea" fill="url(#green-fill)" />
            <path class="area area-red" :d="redArea" fill="url(#red-fill)" />

            <path class="line line-red" :d="redLine" pathLength="1" />
            <path class="line line-green" :d="greenLine" pathLength="1" />

            <circle class="dot dot-red" :cx="redEnd.x" :cy="redEnd.y" r="4.5" />
            <circle class="dot dot-green" :cx="greenEnd.x" :cy="greenEnd.y" r="5" />
        </svg>
    </div>
</template>

<style scoped>
.stage {
    --bg: #010204;
    --bg-2: #040810;
    --ink: #d8dde8;
    --ink-soft: #7a8599;
    --ink-faint: #2f3545;
    --green: #2ecc71;
    --red: #e74c3c;
    --line: rgba(255, 255, 255, 0.04);

    position: fixed;
    inset: 0;
    width: 100vw;
    height: 100vh;
    height: 100dvh;
    overflow: hidden;
    background: var(--bg);
    color: var(--ink);
    font-family: 'IBM Plex Mono', ui-monospace, monospace;
    font-variant-numeric: tabular-nums;
    -webkit-font-smoothing: antialiased;
}

.stage::before {
    content: '∞';
    position: absolute;
    font-family: 'Newsreader', serif;
    font-size: min(96vw, 96vh);
    font-weight: 700;
    color: rgba(255, 255, 255, 0.011);
    top: 50%;
    left: 50%;
    transform: translate(-50%, -46%) rotate(-8deg);
    translate: calc(var(--mx, 0) * -220px) calc(var(--my, 0) * -160px);
    z-index: 0;
    pointer-events: none;
    user-select: none;
    line-height: 1;
    letter-spacing: -0.05em;
}

/* ── 3D depth plane ─────────────────────────────────────────────────────── */
.depth-bg {
    position: absolute;
    inset: -12%;
    width: 124%;
    height: 124%;
    transform: perspective(900px)
               rotateX(calc(var(--my, 0) * -7deg))
               rotateY(calc(var(--mx, 0) * 10deg));
    transform-origin: 50% 45%;
    will-change: transform;
}

/* ── Background ──────────────────────────────────────────────────────────── */
.bg-base {
    position: absolute;
    inset: 0;
    background:
        radial-gradient(ellipse 28% 36% at 18% 82%, rgba(10, 40, 8, 0.45), transparent),
        radial-gradient(ellipse 32% 40% at 82% 75%, rgba(40, 6, 6, 0.38), transparent),
        radial-gradient(110% 80% at 50% -8%, #06091a 0%, #040610 42%, #010204 100%);
}

.blob {
    position: absolute;
    border-radius: 50%;
    filter: blur(110px);
    will-change: transform;
}

.blob-green {
    left: -8%;
    bottom: -16%;
    width: 44vw;
    height: 44vw;
    background: rgba(46, 204, 113, 0.38);
    animation: drift-green 26s ease-in-out infinite, blob-breathe-green 5.2s ease-in-out infinite;
    transition: background 4s ease;
}

.blob-red {
    right: -10%;
    bottom: -18%;
    width: 42vw;
    height: 42vw;
    background: rgba(231, 76, 60, 0.32);
    animation: drift-red 32s ease-in-out infinite reverse, blob-breathe-red 6.8s ease-in-out infinite reverse;
    transition: background 4s ease;
}

@keyframes blob-breathe-green {
    0%, 100% { filter: blur(110px); }
    50%       { filter: blur(82px) brightness(1.35); }
}

@keyframes blob-breathe-red {
    0%, 100% { filter: blur(110px); }
    50%       { filter: blur(86px) brightness(1.28); }
}

.blob-blue {
    left: 26%;
    top: -24%;
    width: 48vw;
    height: 48vw;
    background: rgba(50, 80, 170, 0.20);
    animation: drift-blue 40s ease-in-out infinite;
}

.blob-violet {
    right: 12%;
    top: 8%;
    width: 36vw;
    height: 36vw;
    background: rgba(130, 20, 180, 0.14);
    animation: drift-violet 37s ease-in-out infinite;
}

@keyframes drift-green {
    0%, 100% { transform: translate(0, 0) scale(1); }
    50% { transform: translate(9%, -7%) scale(1.14); }
}

@keyframes drift-red {
    0%, 100% { transform: translate(0, 0) scale(1.06); }
    50% { transform: translate(-8%, -5%) scale(0.90); }
}

@keyframes drift-blue {
    0%, 100% { transform: translate(0, 0) scale(1); }
    50% { transform: translate(-7%, 10%) scale(1.18); }
}

@keyframes drift-violet {
    0%, 100% { transform: translate(0, 0) scale(1); }
    33%  { transform: translate(-9%, 6%) scale(0.86); }
    66%  { transform: translate(7%, -9%) scale(1.12); }
}

.grain {
    position: absolute;
    inset: -6%;
    width: 112%;
    height: 112%;
    opacity: 0.09;
    mix-blend-mode: overlay;
    pointer-events: none;
    animation: grain-static 0.18s steps(1) infinite;
}

@keyframes grain-static {
    0%   { transform: translate(0, 0); opacity: 0.08; }
    14%  { transform: translate(-4%, 3%); opacity: 0.10; }
    28%  { transform: translate(3%, -4%); opacity: 0.07; }
    42%  { transform: translate(-2%, -3%); opacity: 0.11; }
    57%  { transform: translate(4%, 2%); opacity: 0.08; }
    71%  { transform: translate(-3%, 4%); opacity: 0.10; }
    85%  { transform: translate(2%, -2%); opacity: 0.09; }
    100% { transform: translate(0, 0); opacity: 0.08; }
}

.haze {
    position: absolute;
    inset: 0;
    pointer-events: none;
    background:
        radial-gradient(ellipse 68% 58% at 50% 44%, transparent 0%, rgba(1, 2, 4, 0.72) 62%, rgba(1, 2, 4, 0.97) 100%);
}

/* ── Scan lines + sweep ──────────────────────────────────────────────────── */
.scan-lines {
    position: absolute;
    inset: 0;
    z-index: 6;
    pointer-events: none;
    background: repeating-linear-gradient(
        0deg,
        rgba(0, 0, 0, 0.10) 0px,
        rgba(0, 0, 0, 0.10) 1px,
        transparent        1px,
        transparent        4px
    );
}

.sweep {
    position: absolute;
    left: 0;
    right: 0;
    height: 200px;
    background: linear-gradient(
        to bottom,
        transparent,
        rgba(255, 255, 255, 0.016) 50%,
        transparent
    );
    animation: sweep-down 22s linear infinite;
    z-index: 5;
    pointer-events: none;
}

@keyframes sweep-down {
    0%   { top: -22%; }
    100% { top: 122%; }
}

/* ── Header ──────────────────────────────────────────────────────────────── */
.header {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    z-index: 3;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 32px clamp(28px, 5vw, 72px);
    translate: calc(var(--mx, 0) * 14px) calc(var(--my, 0) * 10px);
    will-change: translate;
}

.wordmark {
    font-family: 'Newsreader', serif;
    font-weight: 400;
    font-size: 21px;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--ink);
    text-decoration: none;
    animation: wordmark-flicker 16s linear infinite;
}

@keyframes wordmark-flicker {
    0%, 88%, 100% { opacity: 1; }
    89%   { opacity: 0.25; }
    89.1% { opacity: 1; }
    89.4% { opacity: 0.55; }
    89.5% { opacity: 1; }
    90%   { opacity: 0.15; }
    90.1% { opacity: 1; }
    90.4% { opacity: 0.7; }
    90.5% { opacity: 1; }
}

.wordmark-dot {
    color: var(--green);
}

.admin-pill {
    font-family: 'IBM Plex Mono', ui-monospace, monospace;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.22em;
    color: var(--ink-soft);
    text-decoration: none;
    padding: 11px 22px;
    border: 1px solid var(--line);
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.015);
    backdrop-filter: blur(8px);
    transition: color 0.35s ease, border-color 0.35s ease, background 0.35s ease;
}

.admin-pill:hover {
    color: var(--ink);
    border-color: rgba(46, 204, 113, 0.45);
    background: rgba(46, 204, 113, 0.06);
}

/* ── Hero ────────────────────────────────────────────────────────────────── */
.hero {
    position: absolute;
    top: clamp(120px, 17vh, 200px);
    left: 50%;
    z-index: 2;
    display: flex;
    flex-direction: column;
    align-items: center;
    width: min(92vw, 1000px);
    text-align: center;
    /* Centering via translate (frees transform for 3D tilt) */
    translate: calc(-50% + calc(var(--mx, 0) * -32px)) calc(var(--my, 0) * -22px);
    transform: perspective(700px)
               rotateX(calc(var(--my, 0) * 3deg))
               rotateY(calc(var(--mx, 0) * -3deg));
    will-change: transform, translate;
}

.eyebrow {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0;
    font-size: 11.5px;
    letter-spacing: 0.34em;
    text-transform: uppercase;
    color: var(--ink-faint);
}

.pulse-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--green);
    box-shadow: 0 0 0 0 rgba(46, 204, 113, 0.55);
    animation: ring-pulse 2.4s ease-out infinite;
}

@keyframes ring-pulse {
    0% { box-shadow: 0 0 0 0 rgba(46, 204, 113, 0.5); }
    70% { box-shadow: 0 0 0 11px rgba(46, 204, 113, 0); }
    100% { box-shadow: 0 0 0 0 rgba(46, 204, 113, 0); }
}

.headline {
    margin: 26px 0 0;
    font-family: 'Newsreader', serif;
    font-weight: 300;
    font-size: clamp(40px, 6.4vw, 86px);
    line-height: 1.04;
    letter-spacing: -0.015em;
    max-width: 14ch;
    text-wrap: balance;
    animation: headline-glitch 18s linear infinite;
}

@keyframes headline-glitch {
    0%, 76%, 100% {
        text-shadow: none;
        transform: translate3d(0, 0, 0);
    }
    77% {
        text-shadow: -7px 0 rgba(231, 76, 60, 0.85), 7px 0 rgba(46, 204, 113, 0.85);
        transform: translate3d(-4px, 0, 0);
    }
    77.4% {
        text-shadow: 7px 0 rgba(231, 76, 60, 0.85), -7px 0 rgba(46, 204, 113, 0.85);
        transform: translate3d(5px, 2px, 0);
    }
    77.8% {
        text-shadow: -3px 0 rgba(130, 20, 180, 0.75), 3px 0 rgba(231, 76, 60, 0.6);
        transform: translate3d(-2px, -2px, 0);
        letter-spacing: -0.025em;
    }
    78.1% {
        text-shadow: none;
        transform: translate3d(1px, 0, 0);
    }
    78.3% {
        text-shadow: none;
        transform: translate3d(0, 0, 0);
    }
}

.headline-your {
    font-style: italic;
    color: #b8c2d4;
    text-shadow: 0 0 40px rgba(130, 20, 180, 0.2);
}

/* ── Stage-wide aberration burst ────────────────────────────────────────── */
.stage::after {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    z-index: 9;
    opacity: 0;
    animation: stage-aberration 22s steps(1) infinite;
}

@keyframes stage-aberration {
    0%, 80%, 100% { opacity: 0; background: transparent; }
    81%   { opacity: 1; background: rgba(231, 76, 60, 0.06); }
    81.1% { opacity: 1; background: rgba(46, 204, 113, 0.05); }
    81.2% { opacity: 1; background: rgba(130, 20, 180, 0.04); }
    81.3% { opacity: 0; }
    81.7% { opacity: 1; background: rgba(231, 76, 60, 0.03); }
    81.8% { opacity: 0; }
}

/* ── Second sweep (upward, violet tint) ─────────────────────────────────── */
.sweep-2 {
    height: 140px;
    background: linear-gradient(
        to top,
        transparent,
        rgba(130, 20, 180, 0.014) 50%,
        transparent
    );
    animation: sweep-up 34s linear infinite;
}

@keyframes sweep-up {
    0%   { top: 122%; }
    100% { top: -22%; }
}

/* ── Glitch bars ─────────────────────────────────────────────────────────── */
.glitch-bar {
    position: absolute;
    left: 0;
    right: 0;
    z-index: 7;
    pointer-events: none;
    opacity: 0;
}

.glitch-bar-a {
    height: 3px;
    background: linear-gradient(90deg, transparent 5%, rgba(231, 76, 60, 0.75), transparent 95%);
    animation: gbar-a 11s steps(1) infinite;
}

.glitch-bar-b {
    height: 2px;
    background: linear-gradient(90deg, transparent 15%, rgba(46, 204, 113, 0.6), transparent 85%);
    animation: gbar-b 17s steps(1) infinite;
}

.glitch-bar-c {
    height: 1px;
    background: rgba(255, 255, 255, 0.7);
    animation: gbar-c 14s steps(1) infinite;
}

@keyframes gbar-a {
    0%, 78%, 100% { opacity: 0; top: 38%; }
    79%   { opacity: 1; top: 38%; }
    79.15%{ opacity: 0; top: 62%; }
    79.2% { opacity: 1; top: 62%; }
    79.35%{ opacity: 0; }
}

@keyframes gbar-b {
    0%, 65%, 100% { opacity: 0; top: 24%; }
    66%   { opacity: 0.85; top: 24%; }
    66.1% { opacity: 0; top: 57%; }
    66.2% { opacity: 0.6; top: 57%; }
    66.35%{ opacity: 0; }
}

@keyframes gbar-c {
    0%, 55%, 100% { opacity: 0; top: 71%; }
    56%   { opacity: 1; top: 71%; }
    56.08%{ opacity: 0; top: 33%; }
    56.12%{ opacity: 1; top: 33%; }
    56.18%{ opacity: 0; }
}

/* ── Indices ticker ─────────────────────────────────────────────────────── */
.ticker-wrap {
    position: absolute;
    bottom: calc(54vh - 88px);
    left: 0;
    right: 0;
    z-index: 2;
    translate: 0 calc(var(--my, 0) * -14px);
    will-change: translate;
    height: 52px;
    /* backdrop-filter lives here, separate from the overflow container below */
    background: rgba(1, 2, 6, 0.78);
    -webkit-backdrop-filter: blur(20px) saturate(1.2);
    backdrop-filter: blur(20px) saturate(1.2);
    border-top: 1px solid rgba(255, 255, 255, 0.07);
    border-bottom: 1px solid rgba(255, 255, 255, 0.07);
}

/* Edge fades on top of everything */
.ticker-wrap::before,
.ticker-wrap::after {
    content: '';
    position: absolute;
    top: 0;
    bottom: 0;
    width: 100px;
    z-index: 1;
    pointer-events: none;
}

.ticker-wrap::before {
    left: 0;
    background: linear-gradient(to right, rgba(1, 2, 4, 0.98), transparent);
}

.ticker-wrap::after {
    right: 0;
    background: linear-gradient(to left, rgba(1, 2, 4, 0.98), transparent);
}

/* overflow: hidden on its own element, away from backdrop-filter */
.ticker-overflow {
    position: absolute;
    inset: 0;
    overflow: hidden;
}

.ticker-track {
    display: flex;
    width: max-content;
    align-items: center;
    height: 100%;
    animation: ticker-scroll var(--ticker-duration, 180s) linear infinite;
}

@keyframes ticker-scroll {
    from { transform: translateX(0); }
    to   { transform: translateX(-50%); }
}

.ticker-item {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding-right: 44px;
}

.ticker-symbol {
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 0.10em;
    color: var(--ink);
}

.ticker-country {
    font-size: 10.5px;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--ink-faint);
}

.ticker-price {
    font-size: 12.5px;
    color: var(--ink-soft);
}

.ticker-change {
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.04em;
}

.change-up   { color: var(--green); }
.change-down { color: var(--red);   }
.change-flat { color: var(--ink-faint); }

.ticker-div {
    color: rgba(255, 255, 255, 0.13);
    font-size: 18px;
    font-weight: 300;
    padding-left: 4px;
}

.ticker-item.is-updated .ticker-price,
.ticker-item.is-updated .ticker-change {
    animation: value-flash 2.2s ease;
}

@keyframes value-flash {
    0%   { filter: none; }
    8%   { filter: brightness(3) saturate(1.4); }
    100% { filter: none; }
}

/* ── Chart ───────────────────────────────────────────────────────────────── */
.chart {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 1;
    width: 100%;
    height: 54vh;
    min-height: 340px;
}

.grid-line {
    stroke: var(--line);
    stroke-width: 1;
}

.line {
    fill: none;
    stroke-width: 2.4;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-dasharray: 1;
    stroke-dashoffset: 1;
}

.line-green {
    stroke: var(--green);
    animation:
        draw 5200ms cubic-bezier(0.25, 0.6, 0.2, 1) 350ms forwards,
        glow-green 4.6s ease-in-out 5550ms infinite;
}

.line-red {
    stroke: var(--red);
    animation:
        draw 5200ms cubic-bezier(0.25, 0.6, 0.2, 1) 600ms forwards,
        glow-red 5.4s ease-in-out 5800ms infinite;
}

@keyframes draw {
    to { stroke-dashoffset: 0; }
}

@keyframes glow-green {
    0%, 100% { filter: drop-shadow(0 0 6px rgba(46, 204, 113, 0.4)); }
    50% { filter: drop-shadow(0 0 22px rgba(46, 204, 113, 0.85)); }
}

@keyframes glow-red {
    0%, 100% { filter: drop-shadow(0 0 5px rgba(231, 76, 60, 0.32)); }
    50% { filter: drop-shadow(0 0 18px rgba(231, 76, 60, 0.70)); }
}

.area {
    opacity: 0;
    animation: fade-area 1.4s ease 5550ms forwards;
}

@keyframes fade-area {
    to { opacity: 1; }
}

.dot {
    opacity: 0;
    animation: fade-dot 0.8s ease 5800ms forwards;
}

.dot-green {
    fill: var(--green);
}

.dot-red {
    fill: var(--red);
}

@keyframes fade-dot {
    to { opacity: 1; }
}

/* ── Reduced motion: render the final drawn state, skip all loops ─────────── */
.stage.is-still .blob,
.stage.is-still .pulse-dot,
.stage.is-still .grain,
.stage.is-still .sweep,
.stage.is-still .glitch-bar,
.stage.is-still .headline,
.stage.is-still .wordmark,
.stage.is-still .line,
.stage.is-still .area,
.stage.is-still .dot {
    animation: none;
}

.stage.is-still::after { animation: none; }

.stage.is-still .depth-bg {
    transform: none;
}

.stage.is-still .hero {
    translate: -50% 0;
    transform: none;
}

.stage.is-still .header,
.stage.is-still .ticker-wrap {
    translate: none;
}

.stage.is-still .line {
    stroke-dashoffset: 0;
}

.stage.is-still .area,
.stage.is-still .dot {
    opacity: 1;
}

@media (prefers-reduced-motion: reduce) {
    .blob,
    .pulse-dot,
    .grain,
    .sweep,
    .glitch-bar,
    .headline,
    .wordmark,
    .line,
    .area,
    .dot {
        animation: none !important;
    }

    .stage::after { animation: none !important; }

    .depth-bg { transform: none !important; }
    .hero { translate: -50% 0 !important; transform: none !important; }
    .header, .ticker-wrap { translate: none !important; }

    .line {
        stroke-dashoffset: 0;
    }

    .area,
    .dot {
        opacity: 1;
    }
}
</style>
