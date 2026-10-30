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
const errorGeneral = ref<string | null>(null)

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
  <main class="flex min-h-dvh items-center justify-center px-4 py-10">
    <div class="w-full max-w-sm">
      <div class="mb-8 text-center">
        <div
          class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-primary text-primary-contrast shadow-sm"
        >
          <i class="pi pi-building text-2xl" aria-hidden="true" />
        </div>
        <h1 class="text-2xl font-semibold tracking-tight text-surface-900">SICCO</h1>
        <p class="mt-1 text-sm text-surface-500">Control de consumo de concreto en obra</p>
      </div>

      <form
        class="space-y-5 rounded-2xl border border-surface-200 bg-white p-6 shadow-sm"
        novalidate
        @submit="ingresar"
      >
        <Message v-if="errorGeneral" severity="error" :closable="false">{{ errorGeneral }}</Message>

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

        <Button type="submit" label="Ingresar" :loading="isSubmitting" fluid />
      </form>
    </div>
  </main>
</template>
