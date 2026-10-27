import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import ConfirmationService from 'primevue/confirmationservice'

import App from './App.vue'
import router from './router'
import { tema, localeEs } from './plugins/primevue'
import { registrarInterceptores } from './api/interceptores'

const app = createApp(App)

app.use(createPinia())
app.use(PrimeVue, { theme: tema, locale: localeEs, ripple: true })
app.use(ToastService)
app.use(ConfirmationService)

registrarInterceptores(router, app.config.globalProperties.$toast)

app.use(router)

app.mount('#app')
