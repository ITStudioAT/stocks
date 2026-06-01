import '../css/app.css';
import '@mdi/font/css/materialdesignicons.css';
import 'vuetify/styles';

import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { createStocksVuetify } from './plugins/vuetify';

createApp(App)
    .use(createPinia())
    .use(createStocksVuetify())
    .mount('#app');
