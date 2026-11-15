<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * Edificio que se construye piso por piso mientras la grúa baja el balde de concreto al piso que
 * se vacía. Decorativo: se oculta a los lectores de pantalla y, con «reducir movimiento», muestra
 * el edificio terminado y quieto.
 */

const PISOS = 6
const ALTO_PISO = 38
const SUELO = 372
const PASO_MS = 1400
// Al terminar se mantiene unos pasos y vuelve a empezar.
const PAUSA_PASOS = 3

const pisos = ref(0)
let paso = 0
let temporizador: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  const reducir = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
  if (reducir) {
    pisos.value = PISOS
    return
  }
  temporizador = setInterval(() => {
    paso = (paso + 1) % (PISOS + PAUSA_PASOS + 1)
    pisos.value = Math.min(paso, PISOS)
  }, PASO_MS)
})
onBeforeUnmount(() => clearInterval(temporizador))

// Piso i (0 abajo): su losa superior.
const techo = (i: number) => SUELO - (i + 1) * ALTO_PISO

// El gancho baja hasta justo encima del piso que se está vaciando.
const pisoEnObra = computed(() => Math.min(pisos.value, PISOS - 1))
const alturaGancho = computed(() => techo(pisoEnObra.value) - 30)
const largoCable = computed(() => alturaGancho.value - 81)
// El carro va y viene sobre la pluma: un lado del edificio en pisos pares y el otro en impares.
const carroX = computed(() => (pisoEnObra.value % 2 === 0 ? 190 : 250))
</script>

<template>
  <div aria-hidden="true">
    <svg viewBox="0 0 480 400" class="w-full" role="presentation">
      <defs>
        <pattern id="obra-plano" width="24" height="24" patternUnits="userSpaceOnUse">
          <path d="M24 0H0V24" fill="none" class="stroke-white/10" stroke-width="1" />
        </pattern>
        <linearGradient id="obra-vidrio" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stop-color="currentColor" stop-opacity="0.55" />
          <stop offset="1" stop-color="currentColor" stop-opacity="0.2" />
        </linearGradient>
      </defs>

      <!-- Plano de fondo con cotas -->
      <rect width="480" height="400" fill="url(#obra-plano)" />
      <g class="stroke-white/25" stroke-width="1" fill="none">
        <path d="M120 386H360" />
        <path d="M120 381V391M360 381V391" />
        <path d="M104 372V144" stroke-dasharray="3 4" />
        <path d="M99 372H109M99 144H109" />
      </g>
      <text x="240" y="398" text-anchor="middle" class="fill-white/40 text-[9px] tracking-widest">
        24.00 m
      </text>

      <!-- Suelo -->
      <path d="M40 372H440" class="stroke-white/50" stroke-width="2" />

      <!-- Pisos -->
      <g class="text-primary-200">
        <g
          v-for="i in PISOS"
          :key="i"
          class="piso"
          :class="{ 'piso--listo': i <= pisos }"
          :style="{ transformOrigin: `240px ${techo(i - 1) + ALTO_PISO}px` }"
        >
          <!-- Columnas y vidrios -->
          <rect
            :x="124"
            :y="techo(i - 1) + 4"
            width="232"
            :height="ALTO_PISO - 4"
            fill="url(#obra-vidrio)"
          />
          <rect
            v-for="c in 5"
            :key="c"
            :x="122 + (c - 1) * 56"
            :y="techo(i - 1) + 4"
            width="8"
            :height="ALTO_PISO - 4"
            class="fill-white/85"
          />
          <!-- Losa -->
          <rect :x="116" :y="techo(i - 1)" width="248" height="5" rx="1" class="fill-white" />
        </g>
      </g>

      <!-- Encofrado del piso en obra -->
      <g
        v-if="pisos < PISOS"
        class="transition-transform duration-700 ease-out"
        :style="{ transform: `translateY(${techo(pisos) - techo(0)}px)` }"
      >
        <rect
          x="116"
          :y="techo(0)"
          width="248"
          :height="ALTO_PISO"
          fill="none"
          class="stroke-amber-300/80"
          stroke-width="1.5"
          stroke-dasharray="6 5"
        />
      </g>

      <!-- Grúa torre -->
      <g class="text-amber-300">
        <!-- Torre en celosía -->
        <g stroke="currentColor" stroke-width="2" fill="none">
          <path d="M392 372V64M412 372V64" />
          <path
            d="M392 372L412 344L392 316L412 288L392 260L412 232L392 204L412 176L392 148L412 120L392 92L412 64"
          />
        </g>
        <!-- Cabina, pluma y contrapluma -->
        <rect x="386" y="58" width="32" height="16" rx="2" fill="currentColor" />
        <path d="M402 58V34" stroke="currentColor" stroke-width="2" />
        <path d="M160 74H470" stroke="currentColor" stroke-width="3" />
        <path
          d="M402 34L170 74M402 34L466 74"
          stroke="currentColor"
          stroke-width="1.2"
          opacity="0.7"
        />
        <rect x="440" y="76" width="26" height="16" rx="1" class="fill-white/80" />
        <circle cx="402" cy="32" r="3" class="luz fill-red-400" />

        <!-- Carro, cable y balde: siguen al piso que se vacía -->
        <g class="carro" :style="{ transform: `translateX(${carroX}px)` }">
          <rect x="-10" y="74" width="20" height="7" rx="1" fill="currentColor" />
          <rect x="-0.75" y="81" width="1.5" :height="largoCable" class="cable fill-white/80" />
          <g class="gancho" :style="{ transform: `translateY(${alturaGancho}px)` }">
            <path d="M0 0V6" class="stroke-white" stroke-width="1.5" />
            <path d="M-12 6H12L8 24H-8Z" class="fill-white" />
            <path d="M-8 12H8" class="stroke-primary-700" stroke-width="1.5" />
          </g>
        </g>
      </g>
    </svg>
  </div>
</template>

<style scoped>
.piso {
  opacity: 0;
  transform: scaleY(0.2);
  transition:
    opacity 0.6s ease-out,
    transform 0.7s cubic-bezier(0.2, 0.8, 0.2, 1);
}

.piso--listo {
  opacity: 1;
  transform: scaleY(1);
}

.carro,
.gancho {
  transition: transform 1s cubic-bezier(0.45, 0, 0.2, 1);
}

.cable {
  transition: height 1s cubic-bezier(0.45, 0, 0.2, 1);
}

.luz {
  animation: parpadeo 1.6s ease-in-out infinite;
}

@keyframes parpadeo {
  50% {
    opacity: 0.25;
  }
}

@media (prefers-reduced-motion: reduce) {
  .piso,
  .carro,
  .gancho,
  .cable {
    transition: none;
  }

  .luz {
    animation: none;
  }
}
</style>
