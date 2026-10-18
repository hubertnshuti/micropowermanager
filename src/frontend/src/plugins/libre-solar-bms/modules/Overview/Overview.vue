<template>
  <widget color="primary" :title="text('title')">
    <section class="battery-view" :aria-busy="busy">
      <p v-if="fixture" class="notice" role="status">
        {{ text("fixture") }}: {{ fixture }}
      </p>
      <div class="battery-actions">
        <md-button v-if="batteryId" to="/libre-solar-bms/overview">
          {{ text("back") }}
        </md-button>
        <md-button
          class="md-primary md-raised"
          :disabled="busy"
          @click="refresh"
        >
          {{ text("refresh") }}
        </md-button>
        <span v-if="fetchedAt">
          {{ text("fetched") }}: {{ timestamp(fetchedAt) }}
        </span>
      </div>
      <p v-if="busy" role="status">{{ text("loading") }}</p>
      <div v-if="error" class="notice" role="alert">
        <p>{{ text(error) }}</p>
        <p v-if="detail || batteries.length">{{ text("outdated") }}</p>
        <md-button v-if="canRetry" :disabled="busy" @click="refresh">
          {{ text("retry") }}
        </md-button>
      </div>

      <template v-if="!batteryId">
        <p>{{ text("listAssessment") }}</p>
        <md-table v-if="batteries.length">
          <md-table-row>
            <md-table-head>{{ text("name") }}</md-table-head>
            <md-table-head>{{ text("source") }}</md-table-head>
            <md-table-head>{{ text("soc") }}</md-table-head>
            <md-table-head>{{ text("sourceAssessment") }}</md-table-head>
            <md-table-head>{{ text("integration") }}</md-table-head>
            <md-table-head>{{ text("observed") }}</md-table-head>
          </md-table-row>
          <md-table-row v-for="battery in batteries" :key="battery.id">
            <md-table-cell>
              <router-link :to="detailLink(battery.id)">
                {{ battery.label || battery.node_id }}
              </router-link>
              <div>{{ battery.node_id }}</div>
            </md-table-cell>
            <md-table-cell>{{ sourceLabel(battery.source) }}</md-table-cell>
            <md-table-cell>{{ metric(battery.soc, "%") }}</md-table-cell>
            <md-table-cell>
              {{ sourceStatus(battery.source_status) }}
            </md-table-cell>
            <md-table-cell>
              {{
                text(
                  integrationState(battery) === "recent"
                    ? "recentCheck"
                    : "noRecentUpdate",
                )
              }}
            </md-table-cell>
            <md-table-cell>{{ timestamp(battery.observed_at) }}</md-table-cell>
          </md-table-row>
        </md-table>
        <md-empty-state
          v-else-if="!busy && !error"
          :md-label="text('empty')"
          :md-description="text('emptyHelp')"
        />
        <div v-if="pagination && !error" class="battery-actions">
          <md-button
            :disabled="busy || page <= 1"
            @click="changePage(page - 1)"
          >
            {{ text("previous") }}
          </md-button>
          <span>{{ text("page", { page, total: pagination.last_page }) }}</span>
          <md-button
            :disabled="busy || page >= pagination.last_page"
            @click="changePage(page + 1)"
          >
            {{ text("next") }}
          </md-button>
        </div>
      </template>

      <template v-else-if="detail">
        <h2>{{ detail.label || detail.node_id }}</h2>
        <p>
          <strong>{{ sourceLabel(detail.source) }}</strong>
          · {{ detail.node_id }}
        </p>
        <div
          class="notice"
          :class="{ attention: integration !== 'recent' }"
          role="status"
        >
          <p>
            <strong>{{ text("integration") }}:</strong>
            {{
              text(integration === "recent" ? "recentCheck" : "noRecentUpdate")
            }}
          </p>
          <p>
            <strong>{{ text("sourceAssessment") }}:</strong>
            {{
              integration === "recent"
                ? sourceStatus(detail.status && detail.status.source_status)
                : text("historicalAssessment") +
                  ": " +
                  sourceStatus(detail.status && detail.status.source_status)
            }}
          </p>
          <p>
            {{ text("checked") }}:
            {{ timestamp(detail.status && detail.status.status_checked_at) }}
          </p>
          <p>
            {{ text("sourceObserved") }}:
            {{
              timestamp(detail.status && detail.status.last_source_observed_at)
            }}
          </p>
          <p>
            {{ text("observed") }}: {{ timestamp(detail.metric_observed_at) }}
          </p>
          <p>
            {{ text("received") }}: {{ timestamp(detail.summary_received_at) }}
          </p>
          <p v-if="integration !== 'recent' || sourceIsStale">
            {{ text("lastKnown") }}
          </p>
        </div>
        <div class="battery-grid">
          <md-card>
            <md-card-header>
              <h3>{{ text("batteryState") }}</h3>
            </md-card-header>
            <md-card-content>
              <dl>
                <template v-for="field in metricFields">
                  <dt :key="field.name + '-label'">{{ text(field.name) }}</dt>
                  <dd :key="field.name + '-value'">
                    {{ metric(summary[field.name], field.unit) }}
                    <small
                      v-if="summary.quality && summary.quality[field.name]"
                    >
                      ({{ qualityLabel(summary.quality[field.name]) }})
                    </small>
                  </dd>
                </template>
                <dt>{{ text("bmsState") }}</dt>
                <dd>{{ summary.bms_state || text("unavailable") }}</dd>
              </dl>
            </md-card-content>
          </md-card>
          <md-card>
            <md-card-header>
              <h3>{{ text("electronics") }}</h3>
            </md-card-header>
            <md-card-content>
              <p>
                {{ text("component_temperature_max") }}:
                {{ metric(summary.component_temperature_max, "°C") }}
              </p>
              <p>{{ text("electronicsLimit") }}</p>
              <h3>{{ text("faults") }}</h3>
              <p>
                {{ text("faultAtObservation") }}:
                {{ timestamp(detail.metric_observed_at) }}
              </p>
              <p v-if="faultState !== 'active'">
                {{
                  text(faultState === "clear" ? "clearFaults" : "unknownFaults")
                }}
              </p>
              <ul v-else class="fault-list">
                <li v-for="fault in summary.faults" :key="fault">
                  {{ faultLabel(fault) }}
                </li>
              </ul>
            </md-card-content>
          </md-card>
        </div>
        <h3>{{ text("history") }}</h3>
        <p>{{ text("historyHelp") }}</p>
        <div v-if="historyError" role="alert" class="notice">
          {{ text(historyError) }}
          <md-button
            v-if="historyError === 'requestFailed'"
            :disabled="busy"
            @click="refresh"
          >
            {{ text("retry") }}
          </md-button>
        </div>
        <div class="history-table" tabindex="0" :aria-label="text('history')">
          <md-table v-if="events.length">
            <md-table-row>
              <md-table-head>{{ text("eventObserved") }}</md-table-head>
              <md-table-head>{{ text("event") }}</md-table-head>
              <md-table-head>{{ text("added") }}</md-table-head>
              <md-table-head>{{ text("cleared") }}</md-table-head>
              <md-table-head>{{ text("received") }}</md-table-head>
            </md-table-row>
            <md-table-row
              v-for="row in events"
              :key="row.event.event_id || row.id"
            >
              <md-table-cell>
                {{ timestamp(row.event.observed_at) }}
              </md-table-cell>
              <md-table-cell>{{ $t(eventLabelKey(row.event)) }}</md-table-cell>
              <md-table-cell>
                {{ faultList(row.event.added_faults) }}
              </md-table-cell>
              <md-table-cell>
                {{ faultList(row.event.cleared_faults) }}
              </md-table-cell>
              <md-table-cell>{{ timestamp(row.received_at) }}</md-table-cell>
            </md-table-row>
          </md-table>
        </div>
        <p v-if="!busy && !historyError && !events.length">
          {{ text("emptyHistory") }}
        </p>
        <div v-if="eventPagination && !historyError" class="battery-actions">
          <md-button
            :disabled="busy || eventPage <= 1"
            @click="changeEventPage(eventPage - 1)"
          >
            {{ text("previous") }}
          </md-button>
          <span>
            {{
              text("page", {
                page: eventPage,
                total: eventPagination.last_page,
              })
            }}
          </span>
          <md-button
            :disabled="busy || eventPage >= eventPagination.last_page"
            @click="changeEventPage(eventPage + 1)"
          >
            {{ text("next") }}
          </md-button>
        </div>
        <details>
          <summary>{{ text("sourceDetails") }}</summary>
          <p>
            {{ text("profile") }}:
            {{ attributes.profile_id || text("unavailable") }}
          </p>
          <p>
            {{ text("firmware") }}:
            {{ attributes.firmware_reference || text("unavailable") }}
          </p>
          <p>
            {{ text("cellChannels") }}: {{ channels(attributes.cell_channels) }}
          </p>
          <p>
            {{ text("sensorChannels") }}:
            {{ channels(attributes.temperature_sensor_channels) }}
          </p>
        </details>
      </template>
    </section>
  </widget>
</template>

<script>
import {
  formatMetric,
  getEventLabelKey,
  getFaultState,
  getIntegrationState,
} from "../../services/BatteryPresentation.js"
import {
  batteryErrorKey,
  createBatteryService,
} from "../../services/BatteryService.js"

import Widget from "@/shared/Widget.vue"

const fixtureProvider =
  process.env.NODE_ENV === "development"
    ? require("../../fixtures/BatteryFixtureRepository.js")
    : null

export default {
  name: "BatteryOverview",
  components: { Widget },
  data() {
    return {
      service: null,
      fixture: null,
      batteries: [],
      detail: null,
      events: [],
      pagination: null,
      eventPagination: null,
      page: 1,
      eventPage: 1,
      busy: false,
      error: null,
      historyError: null,
      fetchedAt: null,
      now: Date.now(),
      generation: 0,
      refreshTimer: null,
      ageTimer: null,
      metricFields: [
        { name: "soc", unit: "%" },
        { name: "soh", unit: "%" },
        { name: "cycle_count", unit: "" },
        { name: "cell_voltage_delta", unit: "V" },
        { name: "cell_temperature_max", unit: "°C" },
      ],
    }
  },
  computed: {
    batteryId() {
      return this.$route.params.id || null
    },
    summary() {
      return this.detail?.summary || {}
    },
    attributes() {
      return this.detail?.attributes || {}
    },
    integration() {
      return getIntegrationState(this.detail, this.now)
    },
    faultState() {
      return getFaultState(this.summary)
    },
    sourceIsStale() {
      return ["stale", "disconnected", "unknown"].includes(
        this.detail?.status?.source_status || "unknown",
      )
    },
    canRetry() {
      return this.error === "requestFailed"
    },
  },
  watch: {
    "$route.fullPath"() {
      this.initialize()
    },
  },
  mounted() {
    this.initialize()
    this.ageTimer = setInterval(() => {
      this.now = Date.now()
    }, 1000)
  },
  beforeDestroy() {
    this.generation++
    clearTimeout(this.refreshTimer)
    clearInterval(this.ageTimer)
  },
  methods: {
    text(key, parameters) {
      return this.$t(`battery.${key}`, parameters)
    },
    metric(value, unit) {
      return formatMetric(value, unit, this.text("unavailable"))
    },
    integrationState(battery) {
      return getIntegrationState(battery, this.now)
    },
    eventLabelKey: getEventLabelKey,
    sourceLabel(source) {
      if (source === "simulator") return this.text("simulated")
      return this.text(source === "hardware" ? "hardware" : "sourceUnknown")
    },
    sourceStatus(status) {
      return this.text(
        `status.${["fresh", "stale", "disconnected"].includes(status) ? status : "unknown"}`,
      )
    },
    qualityLabel(reason) {
      return [
        "unsupported",
        "unavailable",
        "invalid",
        "incomplete",
        "source_error",
        "not_reported",
      ].includes(reason)
        ? this.text(`quality.${reason}`)
        : this.text("unavailable")
    },
    faultLabel(code) {
      const key = `battery.faultNames.${code}`
      return this.$te(key)
        ? this.$t(key)
        : `${this.text("unrecognizedFault")}: ${code}`
    },
    faultList(faults) {
      return Array.isArray(faults) && faults.length
        ? faults.map(this.faultLabel).join(", ")
        : "—"
    },
    channels(values) {
      return Array.isArray(values)
        ? values.join(", ")
        : this.text("unavailable")
    },
    timestamp(value) {
      const instant = typeof value === "string" ? Date.parse(value) : NaN
      if (!Number.isFinite(instant)) return this.text("unavailable")
      const age = Math.floor((this.now - instant) / 1000)
      const absolute = new Date(instant).toISOString()
      return age < 0
        ? `${absolute} (${this.text("clockAhead")})`
        : `${absolute} (${this.text("age", { seconds: age })})`
    },
    detailLink(id) {
      return {
        path: `/libre-solar-bms/batteries/${id}`,
        query: this.fixture ? { fixture: this.fixture } : {},
      }
    },
    async initialize() {
      const generation = ++this.generation
      clearTimeout(this.refreshTimer)
      this.busy = false
      this.detail = null
      this.batteries = []
      this.events = []
      this.pagination = null
      this.eventPagination = null
      this.page = 1
      this.eventPage = 1
      this.error = null
      this.historyError = null
      this.fetchedAt = null
      this.fixture = null
      this.service = createBatteryService()
      if (fixtureProvider && this.$route.query.fixture) {
        const provider = fixtureProvider
        if (generation !== this.generation) return
        if (provider.fixtureNames.includes(this.$route.query.fixture)) {
          this.fixture = this.$route.query.fixture
          this.service = createBatteryService(
            provider.createFixtureRepository(this.fixture),
          )
        }
      }
      if (generation === this.generation) return this.refresh()
    },
    async refresh() {
      if (this.busy || !this.service) return
      clearTimeout(this.refreshTimer)
      const generation = this.generation
      this.busy = true
      this.error = null
      this.historyError = null
      try {
        if (!this.batteryId) {
          const result = await this.service.list(this.page)
          if (generation !== this.generation) return
          this.batteries = result.data
          this.pagination = result.pagination
        } else {
          const detail = await this.service.detail(this.batteryId)
          if (generation !== this.generation) return
          this.detail = detail
          try {
            const result = await this.service.events(
              this.batteryId,
              this.eventPage,
            )
            if (generation !== this.generation) return
            this.events = result.data
            this.eventPagination = result.pagination
          } catch (error) {
            if (generation !== this.generation) return
            this.events = []
            this.eventPagination = null
            this.historyError = batteryErrorKey(error)
          }
        }
        this.fetchedAt = new Date().toISOString()
        this.now = Date.now()
      } catch (error) {
        if (generation !== this.generation) return
        this.error = batteryErrorKey(error)
        if (
          [
            "forbidden",
            "pluginDisabled",
            "sessionExpired",
            "notFound",
          ].includes(this.error)
        ) {
          this.detail = null
          this.batteries = []
          this.events = []
        }
      } finally {
        if (generation === this.generation) {
          this.busy = false
          if (!this.error && !this.historyError) {
            this.refreshTimer = setTimeout(this.refresh, 60000)
          }
        }
      }
    },
    changePage(page) {
      this.page = page
      this.batteries = []
      this.refresh()
    },
    changeEventPage(page) {
      this.eventPage = page
      this.events = []
      this.refresh()
    },
  },
}
</script>

<style lang="scss" scoped>
@font-face {
  font-family: "MPM Battery Inter";
  src: url("@/assets/fonts/inter/Inter.ttf") format("truetype");
  font-weight: 100 900;
  font-display: swap;
}

.battery-view {
  padding: 1.5rem;
  font-family: "MPM Battery Inter", sans-serif;
  color: #1b1b1f;
}
h2 {
  font-weight: 600;
  color: #00466d;
}
h3 {
  font-weight: 500;
}
.battery-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.battery-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 1rem;
  margin: 1rem 0;
}
.notice {
  border-left: 4px solid $brand-primary-light;
  background: $brand-background;
  padding: 1rem;
  margin: 1rem 0;
}
.attention {
  border-left-color: $brand-secondary-dark;
}
.fault-list {
  font-weight: 600;
}
.history-table {
  overflow-x: auto;
}
dl {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem;
}
dd {
  margin: 0;
}
small {
  display: block;
}
details {
  margin-top: 1rem;
  overflow-wrap: anywhere;
}
@media (max-width: 600px) {
  .battery-view {
    padding: 0.75rem;
  }
  .battery-grid {
    grid-template-columns: 1fr;
  }
}
</style>
