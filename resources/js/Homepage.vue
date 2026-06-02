<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

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

// ── Cosmetic live value ─────────────────────────────────────────────────────
const BASE_VALUE = 1284930;
const value = ref(BASE_VALUE);
const deltaPercent = ref(2.41);

const formattedValue = computed(() => value.value.toLocaleString('en-US'));
const formattedDelta = computed(() => deltaPercent.value.toFixed(2));

const reducedMotion = typeof window !== 'undefined'
    && typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

let jitterTimer = null;

function jitter() {
    const drift = Math.round((Math.random() - 0.4) * 4200);
    value.value = Math.max(1200000, BASE_VALUE + drift);
    deltaPercent.value = 1.6 + Math.random() * 1.6;
}

onMounted(() => {
    if (!reducedMotion) {
        jitterTimer = window.setInterval(jitter, 2200);
    }
});

onBeforeUnmount(() => {
    if (jitterTimer !== null) {
        window.clearInterval(jitterTimer);
    }
});
</script>

<template>
    <div class="stage" :class="{ 'is-still': reducedMotion }">
        <!-- Background layers -->
        <div class="bg-base" aria-hidden="true"></div>
        <div class="blob blob-green" aria-hidden="true"></div>
        <div class="blob blob-red" aria-hidden="true"></div>
        <div class="blob blob-blue" aria-hidden="true"></div>
        <div class="grain" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">
                <filter id="grain-noise">
                    <feTurbulence type="fractalNoise" baseFrequency="0.9" numOctaves="2" stitchTiles="stitch" />
                </filter>
                <rect width="100%" height="100%" filter="url(#grain-noise)" />
            </svg>
        </div>
        <div class="haze" aria-hidden="true"></div>

        <!-- Header -->
        <header class="header">
            <a class="wordmark" href="/" aria-label="Stocks home">Stocks<span class="wordmark-dot">.</span></a>
            <a class="admin-pill" :href="adminUrl">Admin</a>
        </header>

        <!-- Hero -->
        <section class="hero">
            <p class="eyebrow">
                <span class="pulse-dot" aria-hidden="true"></span>
                Total holdings · Live
            </p>
            <h1 class="headline">A quiet place to watch <span class="headline-your">your</span> money grow.</h1>
            <div class="value-row">
                <span class="value"><span class="value-dollar">$</span>{{ formattedValue }}</span>
                <span class="delta-pill">▲ {{ formattedDelta }}% today</span>
            </div>
        </section>

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
    --bg: #06080d;
    --bg-2: #0a0e17;
    --ink: #eef1f6;
    --ink-soft: #aab2c2;
    --ink-faint: #5d6678;
    --green: #2ecc71;
    --red: #e74c3c;
    --line: rgba(255, 255, 255, 0.07);

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

/* ── Background ──────────────────────────────────────────────────────────── */
.bg-base {
    position: absolute;
    inset: 0;
    background: radial-gradient(120% 90% at 50% -10%, #0d1320, #0a0e17 45%, #06080d);
}

.blob {
    position: absolute;
    border-radius: 50%;
    filter: blur(90px);
    will-change: transform;
}

.blob-green {
    left: -10%;
    bottom: -20%;
    width: 60vw;
    height: 60vw;
    background: rgba(46, 204, 113, 0.32);
    animation: drift-green 26s ease-in-out infinite;
}

.blob-red {
    right: -12%;
    bottom: -22%;
    width: 58vw;
    height: 58vw;
    background: rgba(231, 76, 60, 0.26);
    animation: drift-red 32s ease-in-out infinite reverse;
}

.blob-blue {
    left: 28%;
    top: -28%;
    width: 64vw;
    height: 64vw;
    background: rgba(70, 110, 190, 0.24);
    animation: drift-blue 40s ease-in-out infinite;
}

@keyframes drift-green {
    0%, 100% { transform: translate(0, 0) scale(1); }
    50% { transform: translate(8%, -6%) scale(1.12); }
}

@keyframes drift-red {
    0%, 100% { transform: translate(0, 0) scale(1.05); }
    50% { transform: translate(-7%, -4%) scale(0.92); }
}

@keyframes drift-blue {
    0%, 100% { transform: translate(0, 0) scale(1); }
    50% { transform: translate(-6%, 9%) scale(1.15); }
}

.grain {
    position: absolute;
    inset: 0;
    opacity: 0.045;
    mix-blend-mode: overlay;
    pointer-events: none;
}

.haze {
    position: absolute;
    inset: 0;
    pointer-events: none;
    background: radial-gradient(140% 120% at 50% 40%, transparent 40%, rgba(6, 8, 13, 0.55));
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
}

.wordmark {
    font-family: 'Newsreader', serif;
    font-weight: 400;
    font-size: 21px;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--ink);
    text-decoration: none;
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
    transform: translateX(-50%);
    z-index: 2;
    display: flex;
    flex-direction: column;
    align-items: center;
    width: min(92vw, 1000px);
    text-align: center;
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
}

.headline-your {
    font-style: italic;
    color: #cfd6e2;
}

.value-row {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-top: 38px;
}

.value {
    font-weight: 300;
    font-size: clamp(30px, 4.6vw, 56px);
    font-variant-numeric: tabular-nums;
    color: var(--ink);
}

.value-dollar {
    color: var(--ink-faint);
    font-size: 0.62em;
}

.delta-pill {
    font-size: 14px;
    padding: 6px 13px;
    border: 1px solid rgba(46, 204, 113, 0.28);
    border-radius: 999px;
    background: rgba(46, 204, 113, 0.06);
    color: var(--green);
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
    0%, 100% { filter: drop-shadow(0 0 5px rgba(46, 204, 113, 0.3)); }
    50% { filter: drop-shadow(0 0 16px rgba(46, 204, 113, 0.65)); }
}

@keyframes glow-red {
    0%, 100% { filter: drop-shadow(0 0 4px rgba(231, 76, 60, 0.22)); }
    50% { filter: drop-shadow(0 0 13px rgba(231, 76, 60, 0.5)); }
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
.stage.is-still .line,
.stage.is-still .area,
.stage.is-still .dot {
    animation: none;
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
    .line,
    .area,
    .dot {
        animation: none !important;
    }

    .line {
        stroke-dashoffset: 0;
    }

    .area,
    .dot {
        opacity: 1;
    }
}
</style>
