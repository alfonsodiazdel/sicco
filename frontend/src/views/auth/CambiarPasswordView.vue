<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { z } from 'zod'
import { useToast } from 'primevue/usetoast'
import Password from 'primevue/password'
import Button from 'primevue/button'
import Message from 'primevue/message'
import { useAuthStore } from '@/stores/auth'
import { erroresDeValidacion, mensajeDeError } from '@/api/http'

const auth = useAuthStore()
const router = useRouter()
const toast = useToast()

const requerido = 'Este dato es requerido'

const { defineField, handleSubmit, errors, setErrors, isSubmitting } = useForm({
  validationSchema: toTypedSchema(
    z
      .object({
        password_actual: z.string({ required_error: requerido }).min(1, requerido),
        password: z
          .string({ required_error: requerido })
          .min(8, 'Debe tener al menos 8 caracteres')
          .regex(/[A-Za-z]/, 'Debe incluir al menos una letra')
          .regex(/\d/, 'Debe incluir al menos un número'),
        password_confirmation: z.string({ required_error: requerido }).min(1, requerido),
      })
      .refine((v) => v.password === v.password_confirmation, {
        path: ['password_confirmation'],
        message: 'Las contraseñas no coinciden',
      }),
  ),
})

const [actual, actualAttrs] = defineField('password_actual')
const [nueva, nuevaAttrs] = defineField('password')
const [confirmacion, confirmacionAttrs] = defineField('password_confirmation')

const guardar = handleSubmit(async (valores) => {
  try {
    await auth.cambiarPassword(valores)
    toast.add({ severity: 'success', summary: 'Contraseña actualizada correctamente', life: 4000 })
    await router.replace('/')
  } catch (error) {
    const campos = erroresDeValidacion(error)
    if (Object.keys(campos).length) setErrors(campos)
    else toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
  }
})
</script>

<template>
  <main class="flex min-h-dvh items-center justify-center px-4 py-10">
    <form
      class="w-full max-w-sm space-y-5 rounded-2xl border border-surface-200 bg-white p-6 shadow-sm"
      novalidate
      @submit="guardar"
    >
      <div>
        <h1 class="text-xl font-semibold text-surface-900">Cambiar contraseña</h1>
        <p class="mt-1 text-sm text-surface-500">{{ auth.usuario?.email }}</p>
      </div>

      <Message v-if="auth.usuario?.debe_cambiar_password" severity="info" :closable="false">
        Por seguridad, reemplace la contraseña temporal que recibió por correo.
      </Message>

      <div class="space-y-1.5">
        <label for="password_actual" class="block text-sm font-medium text-surface-700"
          >Contraseña actual</label
        >
        <Password
          v-model="actual"
          v-bind="actualAttrs"
          input-id="password_actual"
          :feedback="false"
          toggle-mask
          autocomplete="current-password"
          :invalid="!!errors.password_actual"
          fluid
        />
        <small v-if="errors.password_actual" class="text-red-600">{{
          errors.password_actual
        }}</small>
      </div>

      <div class="space-y-1.5">
        <label for="password" class="block text-sm font-medium text-surface-700"
          >Nueva contraseña</label
        >
        <Password
          v-model="nueva"
          v-bind="nuevaAttrs"
          input-id="password"
          toggle-mask
          autocomplete="new-password"
          :invalid="!!errors.password"
          fluid
        />
        <small v-if="errors.password" class="text-red-600">{{ errors.password }}</small>
      </div>

      <div class="space-y-1.5">
        <label for="password_confirmation" class="block text-sm font-medium text-surface-700">
          Confirmar nueva contraseña
        </label>
        <Password
          v-model="confirmacion"
          v-bind="confirmacionAttrs"
          input-id="password_confirmation"
          :feedback="false"
          toggle-mask
          autocomplete="new-password"
          :invalid="!!errors.password_confirmation"
          fluid
        />
        <small v-if="errors.password_confirmation" class="text-red-600">{{
          errors.password_confirmation
        }}</small>
      </div>

      <div class="flex flex-col gap-2">
        <Button type="submit" label="Guardar" :loading="isSubmitting" fluid />
        <Button
          v-if="!auth.usuario?.debe_cambiar_password"
          type="button"
          label="Cancelar"
          severity="secondary"
          text
          fluid
          @click="router.back()"
        />
        <Button
          v-else
          type="button"
          label="Cerrar sesión"
          severity="secondary"
          text
          fluid
          @click="auth.logout().then(() => router.replace({ name: 'login' }))"
        />
      </div>
    </form>
  </main>
</template>
