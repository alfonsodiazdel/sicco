<script setup lang="ts">
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import InputText from 'primevue/inputtext'

const texto = defineModel<string>({ default: '' })

const { largo = 100 } = defineProps<{ placeholder: string; etiqueta: string; largo?: number }>()
</script>

<template>
  <IconField class="w-full">
    <InputIcon class="pi pi-search" />
    <InputText
      v-model="texto"
      type="search"
      :maxlength="largo"
      :placeholder="placeholder"
      :aria-label="etiqueta"
      fluid
    />
  </IconField>
</template>
