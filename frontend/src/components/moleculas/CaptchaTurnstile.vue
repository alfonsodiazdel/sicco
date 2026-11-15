<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * Widget de Cloudflare Turnstile. Emite el token por v-model; queda vacío si expira o falla.
 * Cada token sirve una sola vez: tras cada envío hay que llamar a reset().
 */
const props = defineProps<{ sitekey: string; action: string }>()
const token = defineModel<string>({ default: '' })

const contenedor = ref<HTMLElement | null>(null)
let widgetId: string | null = null

const SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'
let cargaScript: Promise<void> | null = null

function cargarScript(): Promise<void> {
  if (window.turnstile) return Promise.resolve()
  cargaScript ??= new Promise<void>((resolve, reject) => {
    const script = document.createElement('script')
    script.src = SCRIPT
    script.async = true
    script.defer = true
    script.onload = () => resolve()
    script.onerror = () => {
      cargaScript = null
      reject(new Error('No se pudo cargar Turnstile'))
    }
    document.head.appendChild(script)
  })
  return cargaScript
}

onMounted(async () => {
  try {
    await cargarScript()
  } catch {
    return
  }
  if (!contenedor.value || !window.turnstile) return
  widgetId = window.turnstile.render(contenedor.value, {
    sitekey: props.sitekey,
    action: props.action,
    language: 'es',
    callback: (valor: string) => (token.value = valor),
    'expired-callback': () => (token.value = ''),
    'error-callback': () => {
      token.value = ''
    },
  })
})

onBeforeUnmount(() => {
  if (widgetId !== null) window.turnstile?.remove(widgetId)
})

// Quien llama limpia el token en su formulario (sin disparar la validación).
function reset() {
  if (widgetId !== null) window.turnstile?.reset(widgetId)
}

defineExpose({ reset })
</script>

<template>
  <div ref="contenedor" class="min-h-[65px]" />
</template>
