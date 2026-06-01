import '../css/app.css';
import '@mdi/font/css/materialdesignicons.css';
import 'vuetify/styles';

import { createApp } from 'vue';
import Homepage from './Homepage.vue';
import { createStocksVuetify } from './plugins/vuetify';

createApp(Homepage)
    .use(createStocksVuetify())
    .mount('#homepage');
