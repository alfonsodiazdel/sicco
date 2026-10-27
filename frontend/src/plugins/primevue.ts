import { definePreset } from '@primeuix/themes'
import Aura from '@primeuix/themes/aura'

// Color principal de SICCO (tono concreto/acero azulado).
const SiccoPreset = definePreset(Aura, {
  semantic: {
    primary: {
      50: '{sky.50}',
      100: '{sky.100}',
      200: '{sky.200}',
      300: '{sky.300}',
      400: '{sky.400}',
      500: '{sky.600}',
      600: '{sky.700}',
      700: '{sky.800}',
      800: '{sky.900}',
      900: '{sky.950}',
      950: '{sky.950}',
    },
  },
})

export const tema = {
  preset: SiccoPreset,
  options: {
    darkModeSelector: '.app-dark',
    cssLayer: { name: 'primevue', order: 'theme, base, primevue' },
  },
}

export const localeEs = {
  accept: 'Sí',
  reject: 'No',
  cancel: 'Cancelar',
  clear: 'Limpiar',
  apply: 'Aplicar',
  choose: 'Elegir',
  upload: 'Subir',
  emptyMessage: 'No hay resultados',
  emptyFilterMessage: 'No hay resultados',
  weak: 'Débil',
  medium: 'Media',
  strong: 'Fuerte',
  passwordPrompt: 'Ingrese una contraseña',
  dayNames: ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'],
  dayNamesShort: ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'],
  dayNamesMin: ['D', 'L', 'M', 'X', 'J', 'V', 'S'],
  monthNames: [
    'enero',
    'febrero',
    'marzo',
    'abril',
    'mayo',
    'junio',
    'julio',
    'agosto',
    'septiembre',
    'octubre',
    'noviembre',
    'diciembre',
  ],
  monthNamesShort: [
    'ene',
    'feb',
    'mar',
    'abr',
    'may',
    'jun',
    'jul',
    'ago',
    'sep',
    'oct',
    'nov',
    'dic',
  ],
  today: 'Hoy',
  firstDayOfWeek: 1,
  dateFormat: 'dd/mm/yy',
}
