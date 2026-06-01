<script setup>
const navigationItems = [
    { label: 'Platform', href: '#platform' },
    { label: 'Workflow', href: '#workflow' },
    { label: 'Quality', href: '#quality' },
];

const trustIndicators = [
    { value: 'Multi-client', label: 'Manage separate brands from one admin' },
    { value: 'AI-ready', label: 'Analyze, structure, and preview faster' },
    { value: 'Laravel', label: 'Built on a maintainable production stack' },
];

const features = [
    {
        icon: 'mdi-view-dashboard-outline',
        title: 'One calm workspace',
        text: 'Keep clients, roles, analyses, colors, and publishing decisions in one focused admin experience.',
    },
    {
        icon: 'mdi-palette-outline',
        title: 'Design-aware color systems',
        text: 'Turn selected brand colors into usable tokens for backgrounds, cards, buttons, accents, and text.',
    },
    {
        icon: 'mdi-web-check',
        title: 'Homepage-ready output',
        text: 'Move from client data to polished public pages with clear sections, readable hierarchy, and reliable routes.',
    },
];

const workflowSteps = [
    {
        step: '01',
        title: 'Capture the client',
        text: 'Create the client profile, active company context, and the public URL structure.',
    },
    {
        step: '02',
        title: 'Analyze and shape',
        text: 'Use helper workflows to collect site signals, colors, assets, and navigation patterns.',
    },
    {
        step: '03',
        title: 'Publish confidently',
        text: 'Preview the page, refine content, and launch a clean homepage for the selected client.',
    },
];

const previewImage = `data:image/svg+xml,${encodeURIComponent(`
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 960 620">
    <defs>
        <linearGradient id="page" x1="0" x2="1" y1="0" y2="1">
            <stop stop-color="#fffdf8"/>
            <stop offset="1" stop-color="#eef2ed"/>
        </linearGradient>
        <linearGradient id="hero" x1="0" x2="1" y1="0" y2="1">
            <stop stop-color="#245c4f"/>
            <stop offset="1" stop-color="#31425f"/>
        </linearGradient>
    </defs>
    <rect width="960" height="620" rx="34" fill="#1d2623"/>
    <rect x="22" y="22" width="916" height="576" rx="28" fill="url(#page)"/>
    <rect x="62" y="62" width="836" height="64" rx="18" fill="#ffffff"/>
    <circle cx="96" cy="94" r="16" fill="#245c4f"/>
    <rect x="128" y="84" width="150" height="20" rx="10" fill="#1d2623"/>
    <rect x="662" y="82" width="72" height="24" rx="12" fill="#d9e2ec"/>
    <rect x="750" y="82" width="72" height="24" rx="12" fill="#d9e2ec"/>
    <rect x="838" y="78" width="42" height="32" rx="12" fill="#c57b37"/>
    <rect x="62" y="154" width="500" height="292" rx="28" fill="url(#hero)"/>
    <rect x="104" y="206" width="96" height="28" rx="14" fill="#eaf5ef" opacity=".9"/>
    <rect x="104" y="262" width="350" height="28" rx="14" fill="#ffffff"/>
    <rect x="104" y="308" width="408" height="28" rx="14" fill="#ffffff" opacity=".86"/>
    <rect x="104" y="368" width="154" height="46" rx="16" fill="#c57b37"/>
    <rect x="584" y="154" width="314" height="292" rx="28" fill="#ffffff"/>
    <rect x="628" y="198" width="226" height="26" rx="13" fill="#1d2623"/>
    <rect x="628" y="250" width="118" height="118" rx="26" fill="#eef2ed"/>
    <rect x="766" y="250" width="88" height="118" rx="26" fill="#f6ede1"/>
    <rect x="628" y="394" width="226" height="18" rx="9" fill="#d9e2ec"/>
    <rect x="62" y="474" width="258" height="86" rx="22" fill="#ffffff"/>
    <rect x="352" y="474" width="258" height="86" rx="22" fill="#ffffff"/>
    <rect x="640" y="474" width="258" height="86" rx="22" fill="#ffffff"/>
</svg>
`)}`;
</script>

<template>
    <v-app class="marketing-app">
        <v-app-bar
            class="marketing-app-bar"
            color="transparent"
            flat
            height="82"
        >
            <v-container class="marketing-nav-container">
                <a class="marketing-brand" href="/" aria-label="Stocks home">
                    <span>STK</span>
                    <strong>Stocks</strong>
                </a>

                <nav class="marketing-nav-links" aria-label="Main navigation">
                    <a
                        v-for="item in navigationItems"
                        :key="item.href"
                        :href="item.href"
                    >
                        {{ item.label }}
                    </a>
                </nav>

                <v-btn
                    class="marketing-nav-action"
                    color="primary"
                    href="/admin/login"
                    prepend-icon="mdi-lock-outline"
                    variant="flat"
                >
                    Admin login
                </v-btn>
            </v-container>
        </v-app-bar>

        <v-main>
            <section class="marketing-hero">
                <v-container>
                    <v-row align="center" class="marketing-hero-grid">
                        <v-col cols="12" lg="6">
                            <v-chip
                                class="marketing-eyebrow"
                                color="primary"
                                prepend-icon="mdi-sparkles"
                                variant="tonal"
                            >
                                AI-assisted homepage operations
                            </v-chip>

                            <h1>Build polished client homepages from one elegant Laravel workspace.</h1>

                            <p class="marketing-hero-copy">
                                Stocks helps teams manage clients, analyze existing sites, shape design tokens,
                                and publish clean public pages without turning the admin into a maze.
                            </p>

                            <div class="marketing-hero-actions">
                                <v-btn
                                    color="primary"
                                    href="/admin/login"
                                    prepend-icon="mdi-arrow-right"
                                    size="x-large"
                                    variant="flat"
                                >
                                    Open admin
                                </v-btn>
                                <v-btn
                                    color="secondary"
                                    href="#platform"
                                    prepend-icon="mdi-play-circle-outline"
                                    size="x-large"
                                    variant="tonal"
                                >
                                    See platform
                                </v-btn>
                            </div>
                        </v-col>

                        <v-col cols="12" lg="6">
                            <v-card class="marketing-preview-card" elevation="0">
                                <v-img
                                    :src="previewImage"
                                    alt="Stocks dashboard and homepage preview"
                                    aspect-ratio="1.548"
                                    cover
                                />
                            </v-card>
                        </v-col>
                    </v-row>
                </v-container>
            </section>

            <section class="marketing-section marketing-trust-section">
                <v-container>
                    <v-row>
                        <v-col
                            v-for="indicator in trustIndicators"
                            :key="indicator.value"
                            cols="12"
                            md="4"
                        >
                            <v-card class="marketing-trust-card" elevation="0">
                                <strong>{{ indicator.value }}</strong>
                                <span>{{ indicator.label }}</span>
                            </v-card>
                        </v-col>
                    </v-row>
                </v-container>
            </section>

            <section id="platform" class="marketing-section">
                <v-container>
                    <div class="marketing-section-heading">
                        <v-chip color="accent" variant="tonal">Platform</v-chip>
                        <h2>Designed for modern homepage production.</h2>
                        <p>
                            The interface should feel calm and premium, but the workflow stays practical: choose a
                            company, manage a client, analyze the source, and ship a better page.
                        </p>
                    </div>

                    <v-row>
                        <v-col
                            v-for="feature in features"
                            :key="feature.title"
                            cols="12"
                            md="4"
                        >
                            <v-card class="marketing-feature-card" elevation="0">
                                <v-icon :icon="feature.icon" color="primary" size="34" />
                                <h3>{{ feature.title }}</h3>
                                <p>{{ feature.text }}</p>
                            </v-card>
                        </v-col>
                    </v-row>
                </v-container>
            </section>

            <section id="workflow" class="marketing-section marketing-workflow-section">
                <v-container>
                    <v-row align="center">
                        <v-col cols="12" lg="5">
                            <div class="marketing-section-heading is-left">
                                <v-chip color="primary" variant="tonal">Workflow</v-chip>
                                <h2>From client context to public page, without clutter.</h2>
                                <p>
                                    A strong AI-assisted coding setup needs a clear product rhythm. These are the
                                    screens and sections future agents should preserve.
                                </p>
                            </div>
                        </v-col>

                        <v-col cols="12" lg="7">
                            <div class="marketing-step-list">
                                <v-card
                                    v-for="step in workflowSteps"
                                    :key="step.step"
                                    class="marketing-step-card"
                                    elevation="0"
                                >
                                    <span>{{ step.step }}</span>
                                    <div>
                                        <h3>{{ step.title }}</h3>
                                        <p>{{ step.text }}</p>
                                    </div>
                                </v-card>
                            </div>
                        </v-col>
                    </v-row>
                </v-container>
            </section>

            <section id="quality" class="marketing-section">
                <v-container>
                    <v-card class="marketing-credibility-card" elevation="0">
                        <v-row align="center">
                            <v-col cols="12" md="7">
                                <v-chip color="secondary" variant="tonal">Quality bar</v-chip>
                                <h2>Premium UI guidance belongs in the repo, not just in prompts.</h2>
                                <p>
                                    This project now includes a design-system brief for future AI agents, so generated
                                    Laravel, Vue, and Vuetify screens can follow the same spacing, typography, color,
                                    accessibility, and section patterns.
                                </p>
                            </v-col>
                            <v-col cols="12" md="5">
                                <v-card class="marketing-quote-card" elevation="0">
                                    <v-icon color="accent" icon="mdi-format-quote-open" size="38" />
                                    <p>
                                        Every generated screen should look intentional, responsive, and production-ready
                                        before it reaches a user.
                                    </p>
                                </v-card>
                            </v-col>
                        </v-row>
                    </v-card>
                </v-container>
            </section>

            <section class="marketing-section">
                <v-container>
                    <v-card class="marketing-final-cta" elevation="0">
                        <v-chip color="accent" variant="flat">Ready for better screens</v-chip>
                        <h2>Use the admin workspace to create the next homepage.</h2>
                        <p>
                            Start with a client, keep the design system close, and let AI assistance work from real
                            project rules instead of generic demo patterns.
                        </p>
                        <v-btn
                            color="primary"
                            href="/admin/login"
                            prepend-icon="mdi-login"
                            size="x-large"
                            variant="flat"
                        >
                            Continue to admin
                        </v-btn>
                    </v-card>
                </v-container>
            </section>
        </v-main>

        <v-footer class="marketing-footer">
            <v-container class="marketing-footer-inner">
                <span>Stocks</span>
                <span>Laravel, Vue, and Vuetify UI production workspace.</span>
            </v-container>
        </v-footer>
    </v-app>
</template>
