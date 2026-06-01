import { createVuetify } from 'vuetify';
import * as components from 'vuetify/components';
import * as directives from 'vuetify/directives';

export function createStocksVuetify() {
    return createVuetify({
        components,
        directives,
        defaults: {
            VBtn: {
                rounded: 'lg',
            },
            VCard: {
                rounded: 'xl',
            },
            VChip: {
                rounded: 'lg',
            },
        },
        icons: {
            defaultSet: 'mdi',
        },
        theme: {
            defaultTheme: 'stocks',
            themes: {
                stocks: {
                    dark: false,
                    colors: {
                        background: '#f7f7f4',
                        surface: '#ffffff',
                        'surface-bright': '#fffdf8',
                        'surface-variant': '#eef2ed',
                        primary: '#245c4f',
                        secondary: '#31425f',
                        accent: '#c57b37',
                        premium: '#8f6a2f',
                        error: '#b3261e',
                        info: '#2f6f9f',
                        success: '#287d55',
                        warning: '#a15c12',
                    },
                },
            },
        },
    });
}
