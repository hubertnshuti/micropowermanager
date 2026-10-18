<template>
  <section class="battery-setup" :aria-busy="busy">
    <h2>{{ text("title") }}</h2>
    <p>{{ text("setupHelp") }}</p>
    <p v-if="busy" role="status">{{ text("loading") }}</p>
    <p v-else-if="error" role="alert">{{ text(error) }}</p>
    <p v-else-if="ready" role="status">{{ text("setupReady") }}</p>
    <p v-else>{{ text("emptyHelp") }}</p>
    <div class="battery-setup-actions">
      <md-button :disabled="busy" @click="check">
        {{ text("refresh") }}
      </md-button>
      <md-button
        class="md-primary md-raised"
        :disabled="!ready || busy"
        @click="finishSetup"
      >
        {{ text("finishSetup") }}
      </md-button>
    </div>
  </section>
</template>

<script>
import {
  batteryErrorKey,
  createBatteryService,
} from "../../services/BatteryService.js"

import { EventBus } from "@/shared/eventbus.js"

export default {
  name: "BatterySetup",
  data() {
    return {
      busy: false,
      ready: false,
      error: null,
      service: createBatteryService(),
    }
  },
  mounted() {
    this.check()
  },
  methods: {
    text(key) {
      return this.$t(`battery.${key}`)
    },
    async check() {
      if (this.busy) return
      this.busy = true
      this.ready = false
      this.error = null
      try {
        const result = await this.service.list(1)
        this.ready = result.data.length > 0
      } catch (error) {
        this.error = batteryErrorKey(error)
      } finally {
        this.busy = false
      }
    },
    finishSetup() {
      if (this.ready && !this.busy) EventBus.$emit("LibreSolarBms")
    },
  },
}
</script>

<style lang="scss" scoped>
.battery-setup {
  max-width: 32rem;
  padding: 1rem;
  text-align: left;
}
.battery-setup-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
}
</style>
