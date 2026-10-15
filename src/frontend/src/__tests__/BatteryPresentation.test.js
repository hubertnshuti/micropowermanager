import {
  formatMetric,
  getEventLabelKey,
  getFaultState,
  getIntegrationState,
} from "../plugins/libre-solar-bms/services/BatteryPresentation.js"

describe("battery metric presentation", () => {
  it("preserves a measured zero and its unit", () => {
    expect(formatMetric(0, "%")).toBe("0 %")
    expect(formatMetric(-5, "A")).toBe("-5 A")
  })

  it.each([null, undefined, NaN, Infinity, "0"])(
    "does not turn unavailable value %p into zero",
    (value) => expect(formatMetric(value, "V")).toBe("Not available"),
  )

  it("accepts translated unavailable text", () => {
    expect(formatMetric(null, "%", "Unsupported")).toBe("Unsupported")
  })
})

describe("battery fault presentation", () => {
  it.each([null, {}, { faults: null }, { faults: [], error_flags: null }])(
    "keeps missing fault evidence unknown: %p",
    (summary) => expect(getFaultState(summary)).toBe("unknown"),
  )

  it("requires an explicit zero flag mask for a clear state", () => {
    expect(getFaultState({ faults: [], error_flags: 0 })).toBe("clear")
    expect(getFaultState({ faults: [], error_flags: 2147483648 })).toBe(
      "unknown",
    )
  })

  it("preserves unknown fault bits as active faults", () => {
    expect(
      getFaultState({ faults: ["unknown_bit_31"], error_flags: 2147483648 }),
    ).toBe("active")
  })
})

describe("battery integration freshness", () => {
  const checkedTime = Date.parse("2026-10-15T12:00:00.000Z")
  const detail = {
    status: { status_checked_at: "2026-10-15T12:00:00.000Z" },
    integration_status: "recent",
    integration_stale_after_seconds: 150,
  }

  it("ages the original check at precisely 150 seconds", () => {
    expect(getIntegrationState(detail, checkedTime + 149999)).toBe("recent")
    expect(getIntegrationState(detail, checkedTime + 150000)).toBe("stale")
  })

  it("does not reset freshness on a recent receipt or metric observation", () => {
    expect(
      getIntegrationState(
        {
          ...detail,
          status_received_at: "2026-10-15T12:03:00.000Z",
          metric_observed_at: "2026-10-15T12:03:00.000Z",
        },
        checkedTime + 180000,
      ),
    ).toBe("stale")
  })

  it("honors a server stale result even with clock disagreement", () => {
    expect(
      getIntegrationState(
        { ...detail, integration_status: "stale" },
        checkedTime,
      ),
    ).toBe("stale")
  })

  it.each([undefined, null, "invalid", "2026-10-15T13:00:00.000Z"])(
    "does not call an absent, invalid or future check recent: %p",
    (statusCheckedAt) => {
      expect(
        getIntegrationState(
          { ...detail, status: { status_checked_at: statusCheckedAt } },
          checkedTime,
        ),
      ).toBe("unknown")
    },
  )

  it("handles a battery with no status and invalid freshness configuration", () => {
    expect(getIntegrationState(null, checkedTime)).toBe("unknown")
    expect(getIntegrationState({}, checkedTime)).toBe("unknown")
    expect(
      getIntegrationState(
        { ...detail, integration_stale_after_seconds: null },
        checkedTime,
      ),
    ).toBe("unknown")
  })
})

describe("battery event labels", () => {
  it.each([
    ["fault_observed", 130, "faultObserved"],
    ["fault_changed", 130, "faultChanged"],
    ["fault_changed", 0, "faultCleared"],
    ["observation_gap", null, "observationGap"],
    ["future_kind", 0, "unknown"],
  ])("labels %s without treating gaps as clear", (kind, mask, label) => {
    expect(getEventLabelKey({ kind, current_error_flags: mask })).toBe(
      `battery.events.${label}`,
    )
  })
})
