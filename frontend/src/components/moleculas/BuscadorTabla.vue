<script setup lang="ts">
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import InputText from 'primevue/inputtext'

const texto = defineModel<string>({ default: '' })

defineProps<{ placeholder: string; etiqueta: string }>()
</script>

<template>
  <IconField class="w-full">
    <InputIcon class="pi pi-search" />
    <InputText
      v-model="texto"
      type="search"
      maxlength="100"
      :placeholder="placeholder"
      :aria-label="etiqueta"
      fluid
    />
  </IconField>
</template>
