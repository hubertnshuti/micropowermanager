jest.mock("@/shared/Widget.vue", () => ({ name: "Widget" }))
jest.mock(
  "../plugins/libre-solar-bms/repositories/BatteryRepository.js",
  () => ({
    list: jest.fn(),
    detail: jest.fn(),
    events: jest.fn(),
  }),
)

import Vue from "vue"

import Overview from "../plugins/libre-solar-bms/modules/Overview/Overview.vue"
import BatteryRepository from "../plugins/libre-solar-bms/repositories/BatteryRepository.js"

import { EventBus } from "@/shared/eventbus.js"

function deferred() {
  let resolve
  const promise = new Promise((complete) => {
    resolve = complete
  })
  return { promise, resolve }
}

const pagination = { page: 1, per_page: 25, total: 0, last_page: 1 }
const history = (rows = []) => ({
  data: {
    data: rows.map((row) => ({
      ...row,
      event: {
        event_id: `event:${row.id}`,
        kind: "fault_changed",
        observed_at: "2026-10-15T12:00:00.000Z",
      },
    })),
    pagination,
  },
})
const detail = (id) => ({ data: { data: { id, summary: { soc: 0 } } } })
const flush = async () => {
  for (let index = 0; index < 12; index++) await Promise.resolve()
}

describe("battery overview script lifecycle (without rendering templates)", () => {
  let instance
  function create(id = "1") {
    const Component = Vue.extend(Overview)
    instance = new Component()
    Vue.util.defineReactive(instance, "$route", {
      params: { id },
      query: {},
      fullPath: `/battery/${id}`,
    })
    return instance
  }

  beforeEach(() => {
    jest.resetAllMocks()
    jest.useFakeTimers()
    BatteryRepository.events.mockResolvedValue(history())
  })

  afterEach(() => {
    if (instance && !instance._isDestroyed) instance.$destroy()
    jest.clearAllTimers()
    jest.useRealTimers()
  })

  it("completes the generated setup hook with its exact event and no battery request", () => {
    create(null)
    const emit = jest.spyOn(EventBus, "$emit")
    try {
      instance.finishSetup()
      expect(emit).toHaveBeenCalledTimes(1)
      expect(emit).toHaveBeenCalledWith("LibreSolarBms")
      expect(BatteryRepository.list).not.toHaveBeenCalled()
      expect(BatteryRepository.detail).not.toHaveBeenCalled()
      expect(BatteryRepository.events).not.toHaveBeenCalled()
    } finally {
      emit.mockRestore()
    }
  })

  it("ignores a delayed battery A detail after navigation to B", async () => {
    const pending = deferred()
    BatteryRepository.detail.mockImplementation((id) =>
      id === "1" ? pending.promise : Promise.resolve(detail(2)),
    )
    create()
    instance.initialize()
    instance.$route = { params: { id: "2" }, query: {}, fullPath: "/battery/2" }
    instance.initialize()
    await flush()
    expect(instance.detail.id).toBe(2)
    pending.resolve(detail(1))
    await flush()
    expect(instance.detail.id).toBe(2)
    expect(BatteryRepository.events).toHaveBeenCalledTimes(1)
  })

  it("ignores pending A history after navigation to B", async () => {
    const pending = deferred()
    BatteryRepository.detail.mockImplementation((id) =>
      Promise.resolve(detail(Number(id))),
    )
    BatteryRepository.events.mockImplementation((id) =>
      id === "1" ? pending.promise : Promise.resolve(history([{ id: 22 }])),
    )
    create()
    instance.initialize()
    await flush()
    instance.$route = { params: { id: "2" }, query: {}, fullPath: "/battery/2" }
    instance.initialize()
    await flush()
    pending.resolve(history([{ id: 11 }]))
    await flush()
    expect(instance.detail.id).toBe(2)
    expect(instance.events).toEqual(history([{ id: 22 }]).data.data)
  })

  it("prevents overlapping manual refresh and schedules one subsequent poll", async () => {
    const pending = deferred()
    BatteryRepository.detail.mockReturnValue(pending.promise)
    create()
    instance.initialize()
    instance.refresh()
    instance.refresh()
    expect(BatteryRepository.detail).toHaveBeenCalledTimes(1)
    pending.resolve(detail(1))
    await flush()
    expect(instance.busy).toBe(false)
    expect(instance.error).toBe(null)
    expect(instance.historyError).toBe(null)
    expect(jest.getTimerCount()).toBe(1)
    jest.advanceTimersByTime(59999)
    expect(BatteryRepository.detail).toHaveBeenCalledTimes(1)
    jest.advanceTimersByTime(1)
    await flush()
    expect(BatteryRepository.detail).toHaveBeenCalledTimes(2)
    await flush()
  })

  it("invalidates pending responses and clears poll and age timers on destroy", async () => {
    const pending = deferred()
    BatteryRepository.detail.mockReturnValue(pending.promise)
    create()
    Overview.mounted.call(instance)
    instance.$destroy()
    pending.resolve(detail(1))
    await flush()
    expect(instance.detail).toBe(null)
    expect(jest.getTimerCount()).toBe(0)
    jest.advanceTimersByTime(120000)
    expect(BatteryRepository.detail).toHaveBeenCalledTimes(1)
  })

  it("retains last known data after a network failure and offers manual retry", async () => {
    BatteryRepository.detail.mockResolvedValue(detail(1))
    create()
    instance.initialize()
    await flush()
    BatteryRepository.detail.mockRejectedValue(new Error("Offline"))
    await instance.refresh()
    expect(instance.error).toBe("requestFailed")
    expect(instance.canRetry).toBe(true)
    expect(instance.detail.id).toBe(1)
    expect(jest.getTimerCount()).toBe(0)
  })

  it("clears protected data on 403 and stops polling", async () => {
    BatteryRepository.detail.mockResolvedValue(detail(1))
    create()
    instance.initialize()
    await flush()
    BatteryRepository.detail.mockRejectedValue({ response: { status: 403 } })
    await instance.refresh()
    expect(instance.error).toBe("forbidden")
    expect(instance.canRetry).toBe(false)
    expect(instance.detail).toBe(null)
    expect(instance.events).toEqual([])
    expect(jest.getTimerCount()).toBe(0)
  })

  it("reports a history-only permission error without hiding the detail", async () => {
    BatteryRepository.detail.mockResolvedValue(detail(1))
    BatteryRepository.events.mockRejectedValue({ response: { status: 403 } })
    create()
    instance.initialize()
    await flush()
    expect(instance.error).toBe(null)
    expect(instance.historyError).toBe("forbidden")
    expect(instance.detail.id).toBe(1)
    expect(instance.events).toEqual([])
    expect(jest.getTimerCount()).toBe(0)
  })
})
