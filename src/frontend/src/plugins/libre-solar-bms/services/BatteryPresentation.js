export function formatMetric(value, unit = "", unavailable = "Not available") {
  if (typeof value !== "number" || !Number.isFinite(value)) return unavailable
  return unit ? `${value} ${unit}` : String(value)
}

export function getFaultState(summary) {
  if (!summary || !Array.isArray(summary.faults)) return "unknown"
  if (summary.faults.length > 0) return "active"
  return summary.error_flags === 0 ? "clear" : "unknown"
}

export function getIntegrationState(detail, now = Date.now()) {
  if (!detail || !detail.status) return "unknown"
  const checkedAt = detail.status.status_checked_at
  const checkedTime =
    typeof checkedAt === "string" ? Date.parse(checkedAt) : NaN
  const threshold = detail.integration_stale_after_seconds
  if (
    !Number.isFinite(checkedTime) ||
    !Number.isFinite(now) ||
    checkedTime > now ||
    typeof threshold !== "number" ||
    !Number.isFinite(threshold) ||
    threshold <= 0
  ) {
    return "unknown"
  }
  if (
    detail.integration_status === "stale" ||
    now - checkedTime >= threshold * 1000
  ) {
    return "stale"
  }
  return detail.integration_status === "recent" ? "recent" : "unknown"
}

export function getEventLabelKey(event) {
  if (!event) return "battery.events.unknown"
  if (event.kind === "observation_gap") return "battery.events.observationGap"
  if (event.kind === "fault_observed") return "battery.events.faultObserved"
  if (event.kind !== "fault_changed") return "battery.events.unknown"
  return event.current_error_flags === 0
    ? "battery.events.faultCleared"
    : "battery.events.faultChanged"
}
