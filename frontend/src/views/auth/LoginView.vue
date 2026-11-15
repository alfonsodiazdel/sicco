<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { z } from 'zod'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Button from 'primevue/button'
import Message from 'primevue/message'
import IlustracionObra from '@/components/organismos/IlustracionObra.vue'
import { useAuthStore } from '@/stores/auth'
import { destinoTrasLogin } from '@/router'
import { erroresDeValidacion, esErrorApi, mensajeDeError } from '@/api/http'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const { defineField, handleSubmit, errors, setErrors, isSubmitting } = useForm({
  validationSchema: toTypedSchema(
    z.object({
      email: z
        .string({ required_error: 'Este dato es requerido' })
        .min(1, 'Este dato es requerido')
        .email('Ingrese un correo válido'),
      password: z
        .string({ required_error: 'Este dato es requerido' })
        .min(1, 'Este dato es requerido'),
    }),
  ),
})

const [email, emailAttrs] = defineField('email')
const [password, passwordAttrs] = defineField('password')
const errorGeneral = ref<string | null>(auth.errorSesion)
const anio = new Date().getFullYear()

const ingresar = handleSubmit(async (valores) => {
  errorGeneral.value = null
  try {
    await auth.login(valores)
    await router.replace(destinoTrasLogin(route.query.redirect))
  } catch (error) {
    const status = esErrorApi(error) ? error.response?.status : undefined
    if (status === 422) {
      // «Correo o contraseña incorrectos» se muestra como mensaje general, no bajo un campo.
      const { email: errorEmail, ...resto } = erroresDeValidacion(error)
      setErrors(resto)
      errorGeneral.value = errorEmail ?? null
    } else {
      errorGeneral.value = mensajeDeError(error)
    }
  }
})
</script>

<template>
  <main class="grid min-h-dvh bg-white lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
    <!-- Panel de marca: solo en pantallas anchas -->
    <section
      class="relative hidden overflow-hidden bg-linear-to-br from-primary-600 via-primary-700 to-primary-900 text-white lg:flex lg:flex-col"
    >
      <div
        class="pointer-events-none absolute -top-32 -right-24 size-96 rounded-full bg-primary-400/20 blur-3xl"
        aria-hidden="true"
      />
      <div class="relative flex items-center gap-3 px-12 pt-10">
        <div
          class="flex size-10 items-center justify-center rounded-xl bg-white/15 ring-1 ring-white/20"
        >
          <i class="pi pi-box" aria-hidden="true" />
        </div>
        <div class="leading-tight">
          <p class="font-semibold tracking-wide">SICCO</p>
          <p class="text-xs text-white/70">Control de concreto premezclado</p>
        </div>
      </div>

      <div class="relative flex flex-1 items-center justify-center px-12 py-4">
        <IlustracionObra class="w-full max-w-[min(32rem,62vh)]" />
      </div>

      <div class="relative px-12 pb-9">
        <h2 class="max-w-md text-3xl leading-tight font-semibold tracking-tight">
          Cada m³ de concreto, bajo control.
        </h2>
      </div>
    </section>

    <!-- Formulario -->
    <section class="flex flex-col px-4 py-10 sm:px-10">
      <div class="flex flex-1 items-center justify-center">
        <div class="w-full max-w-sm">
          <!-- En móvil la marca va sobre el formulario -->
          <div class="mb-8 flex items-center gap-3 lg:hidden">
            <div
              class="flex size-11 items-center justify-center rounded-xl bg-primary text-primary-contrast shadow-sm"
            >
              <i class="pi pi-box" aria-hidden="true" />
            </div>
            <div class="leading-tight">
              <p class="font-semibold text-surface-900">SICCO</p>
              <p class="text-xs text-surface-500">Control de concreto premezclado</p>
            </div>
          </div>

          <h1 class="text-2xl font-semibold tracking-tight text-surface-900">Iniciar sesión</h1>
          <p class="mt-1 text-sm text-surface-500">
            Ingrese con su correo corporativo para continuar.
          </p>

          <form class="mt-8 space-y-5" novalidate @submit="ingresar">
            <Message v-if="errorGeneral" severity="error" :closable="false">{{
              errorGeneral
            }}</Message>

            <div class="space-y-1.5">
              <label for="email" class="block text-sm font-medium text-surface-700"
                >Correo electrónico</label
              >
              <InputText
                id="email"
                v-model="email"
                v-bind="emailAttrs"
                type="email"
                autocomplete="username"
                inputmode="email"
                placeholder="nombre@empresa.com"
                :invalid="!!errors.email"
                fluid
                autofocus
              />
              <small v-if="errors.email" class="text-red-600">{{ errors.email }}</small>
            </div>

            <div class="space-y-1.5">
              <label for="password" class="block text-sm font-medium text-surface-700"
                >Contraseña</label
              >
              <Password
                v-model="password"
                v-bind="passwordAttrs"
                input-id="password"
                :feedback="false"
                toggle-mask
                autocomplete="current-password"
                :invalid="!!errors.password"
                fluid
              />
              <small v-if="errors.password" class="text-red-600">{{ errors.password }}</small>
            </div>

            <Button
              type="submit"
              label="Ingresar"
              icon="pi pi-arrow-right"
              icon-pos="right"
              :loading="isSubmitting"
              fluid
            />
          </form>
        </div>
      </div>

      <p class="text-center text-xs text-surface-400">
        © {{ anio }} SICCO · Sistema de Control de Consumo de Concreto en Obra
      </p>
    </section>
  </main>
</template>
