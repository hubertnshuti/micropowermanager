jest.mock(
  "../plugins/libre-solar-bms/repositories/BatteryRepository.js",
  () => ({
    list: jest.fn(),
    detail: jest.fn(),
    events: jest.fn(),
  }),
)

import BatteryRepository from "../plugins/libre-solar-bms/repositories/BatteryRepository.js"
import {
  batteryErrorKey,
  createBatteryService,
} from "../plugins/libre-solar-bms/services/BatteryService.js"

describe("battery read service", () => {
  const service = createBatteryService()
  const pagination = { page: 1, per_page: 25, last_page: 1, total: 1 }

  beforeEach(() => jest.resetAllMocks())

  it("preserves measured zero and unavailable values in a paginated list", async () => {
    const body = {
      data: [{ id: 1, node_id: "53494D0000000001", soc: 0, soh: null }],
      pagination,
    }
    BatteryRepository.list.mockResolvedValue({ data: body })
    expect(await service.list(1)).toEqual(body)
    expect(BatteryRepository.list).toHaveBeenCalledWith(1)
  })

  it("preserves event gaps and forwards the battery and page", async () => {
    const body = {
      data: [
        {
          event: {
            event_id: "gap:1",
            observed_at: "2026-10-15T12:00:00.000Z",
            kind: "observation_gap",
            faults: null,
          },
        },
      ],
      pagination,
    }
    BatteryRepository.events.mockResolvedValue({ data: body })
    expect(await service.events(7, 2)).toEqual(body)
    expect(BatteryRepository.events).toHaveBeenCalledWith(7, 2)
  })

  it("accepts an empty collection without substituting fabricated data", async () => {
    const body = { data: [], pagination: { ...pagination, total: 0 } }
    BatteryRepository.list.mockResolvedValue({ data: body })
    expect(await service.list(1)).toEqual(body)
  })

  it.each([
    null,
    {},
    { data: {}, pagination },
    { data: [] },
    { data: [], pagination: { ...pagination, page: 0 } },
    { data: [], pagination: { ...pagination, per_page: "25" } },
    { data: [], pagination: { ...pagination, total: -1 } },
    { data: [], pagination: { ...pagination, last_page: 1.5 } },
  ])("rejects malformed list and history bodies: %p", async (body) => {
    BatteryRepository.list.mockResolvedValue({ data: body })
    BatteryRepository.events.mockResolvedValue({ data: body })
    await expect(service.list(1)).rejects.toThrow()
    await expect(service.events(1, 1)).rejects.toThrow()
  })

  it.each([
    null,
    {},
    { id: 0, node_id: "node" },
    { id: "1", node_id: "node" },
    { id: 1, node_id: null },
    { id: 1, node_id: "" },
  ])("rejects unsafe battery rows: %p", async (row) => {
    BatteryRepository.list.mockResolvedValue({
      data: { data: [row], pagination },
    })
    await expect(service.list(1)).rejects.toThrow(
      "Invalid battery collection response",
    )
  })

  it.each([
    null,
    {},
    { event: null },
    { event: [] },
    { event: { kind: "fault_changed" } },
    { event: { event_id: "event:1", kind: "fault_changed" } },
    {
      event: { observed_at: "2026-10-15T12:00:00.000Z", kind: "fault_changed" },
    },
  ])("rejects unsafe history rows: %p", async (row) => {
    BatteryRepository.events.mockResolvedValue({
      data: { data: [row], pagination },
    })
    await expect(service.events(1, 1)).rejects.toThrow(
      "Invalid battery collection response",
    )
  })

  it.each(["summary", "status", "attributes"])(
    "rejects malformed nested %s without accepting an unusable snapshot",
    async (field) => {
      for (const value of [[], "invalid", 0, false]) {
        BatteryRepository.detail.mockResolvedValue({
          data: { data: { id: 1, [field]: value } },
        })
        await expect(service.detail(1)).rejects.toThrow(
          "Invalid battery detail response",
        )
      }
    },
  )

  it("preserves a detail snapshot and accepts a route parameter string", async () => {
    const detail = { id: 1, summary: { soc: 0, faults: null }, status: null }
    BatteryRepository.detail.mockResolvedValue({ data: { data: detail } })
    expect(await service.detail("1")).toEqual(detail)
    expect(BatteryRepository.detail).toHaveBeenCalledWith("1")
  })

  it.each([null, [], {}, { id: "1" }, { id: 2 }, { id: 1.5 }])(
    "rejects missing, malformed or mismatched detail: %p",
    async (detail) => {
      BatteryRepository.detail.mockResolvedValue({ data: { data: detail } })
      await expect(service.detail(1)).rejects.toThrow(
        "Invalid battery detail response",
      )
    },
  )

  it.each(["list", "detail", "events"])(
    "propagates network failure from %s for safe caller retry",
    async (method) => {
      const failure = new Error("Network unavailable")
      BatteryRepository[method].mockRejectedValue(failure)
      await expect(service[method](1, 1)).rejects.toBe(failure)
    },
  )

  it("propagates permission failure without replacing it with an empty list", async () => {
    const failure = { response: { status: 403, data: { code: "forbidden" } } }
    BatteryRepository.list.mockRejectedValue(failure)
    await expect(service.list(1)).rejects.toBe(failure)
  })
})

describe("battery error messages", () => {
  it.each([
    [401, undefined, "sessionExpired"],
    [403, "plugin_disabled", "pluginDisabled"],
    [403, "forbidden", "forbidden"],
    [403, undefined, "forbidden"],
    [404, undefined, "notFound"],
    [500, undefined, "requestFailed"],
  ])("maps HTTP %s and code %s to %s", (status, code, expected) => {
    expect(batteryErrorKey({ response: { status, data: { code } } })).toBe(
      expected,
    )
  })

  it("maps a network error without a response", () => {
    expect(batteryErrorKey(new Error("Network unavailable"))).toBe(
      "requestFailed",
    )
  })
})
