import events from "./events.json"
import fault from "./fault.json"
import normal from "./normal.json"
import unknown from "./unknown.json"

export const fixtureNames = [
  "normal",
  "fault",
  "unknown",
  "zero",
  "stale",
  "empty",
  "error",
  "forbidden",
]

export function createFixtureRepository(scenario) {
  const detail = JSON.parse(
    JSON.stringify(
      scenario === "fault"
        ? fault.data
        : scenario === "unknown"
          ? unknown.data
          : normal.data,
    ),
  )
  const now = Date.now()
  detail.metric_observed_at = new Date(now - 42000).toISOString()
  detail.summary.observed_at = detail.metric_observed_at
  if (detail.status) {
    detail.status.status_checked_at = new Date(
      now - (scenario === "stale" ? 151000 : 2000),
    ).toISOString()
    detail.status.last_source_observed_at = new Date(now - 3000).toISOString()
  }
  detail.integration_status = scenario === "stale" ? "stale" : "recent"
  if (scenario === "zero") {
    for (const field of [
      "soc",
      "cell_voltage_delta",
      "cell_temperature_max",
      "component_temperature_max",
    ]) {
      detail.summary[field] = 0
    }
  }
  const rows =
    scenario === "empty"
      ? []
      : [
          {
            id: detail.id,
            node_id: detail.node_id,
            label: detail.label,
            source: detail.source,
            soc: detail.summary.soc,
            soh: detail.summary.soh,
            cycle_count: detail.summary.cycle_count,
            observed_at: detail.metric_observed_at,
            source_status: detail.status?.source_status ?? "unknown",
            integration_status: detail.integration_status,
            status_checked_at: detail.status?.status_checked_at ?? null,
            integration_stale_after_seconds:
              detail.integration_stale_after_seconds,
          },
        ]
  function respond(data) {
    if (["error", "forbidden"].includes(scenario)) {
      const error = new Error("Explicit fixture failure")
      error.response = {
        status: scenario === "forbidden" ? 403 : 503,
        data: { code: "forbidden" },
      }
      return Promise.reject(error)
    }
    return Promise.resolve({ data })
  }
  function paginated(data) {
    return {
      data,
      pagination: { page: 1, per_page: 25, total: data.length, last_page: 1 },
    }
  }
  return {
    list: () => respond(paginated(rows)),
    detail: () => respond({ data: detail }),
    events: () => respond(paginated(scenario === "fault" ? events.data : [])),
  }
}
