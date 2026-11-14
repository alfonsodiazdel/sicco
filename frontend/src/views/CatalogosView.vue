<script setup lang="ts">
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import GruposView from '@/views/GruposView.vue'
import ElementosView from '@/views/catalogos/ElementosView.vue'
import ResistenciasView from '@/views/catalogos/ResistenciasView.vue'
</script>

<template>
  <Tabs value="grupos" lazy>
    <TabList>
      <Tab value="grupos">Grupos de partidas</Tab>
      <Tab value="elementos">Elementos estructurales</Tab>
      <Tab value="resistencias">Resistencias f'c</Tab>
    </TabList>
    <TabPanels class="!px-0">
      <TabPanel value="grupos"><GruposView /></TabPanel>
      <TabPanel value="elementos"><ElementosView /></TabPanel>
      <TabPanel value="resistencias"><ResistenciasView /></TabPanel>
    </TabPanels>
  </Tabs>
</template>
