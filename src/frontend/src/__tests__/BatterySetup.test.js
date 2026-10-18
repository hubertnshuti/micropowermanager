jest.mock(
  "../plugins/libre-solar-bms/repositories/BatteryRepository.js",
  () => ({ list: jest.fn(), detail: jest.fn(), events: jest.fn() }),
)

import Vue from "vue"

import Setup from "../plugins/libre-solar-bms/modules/Overview/Setup.vue"
import BatteryRepository from "../plugins/libre-solar-bms/repositories/BatteryRepository.js"

import { EventBus } from "@/shared/eventbus.js"

const response = (rows) => ({
  data: {
    data: rows,
    pagination: { page: 1, per_page: 25, total: rows.length, last_page: 1 },
  },
})

describe("battery registration setup", () => {
  let instance
  let emit
  beforeEach(() => {
    jest.resetAllMocks()
    instance = new (Vue.extend(Setup))()
    emit = jest.spyOn(EventBus, "$emit")
  })
  afterEach(() => {
    emit.mockRestore()
    instance.$destroy()
  })

  it("completes only after finding a registered battery without loading dashboard detail or events", async () => {
    instance.finishSetup()
    expect(emit).not.toHaveBeenCalled()
    BatteryRepository.list.mockResolvedValue(
      response([{ id: 1, node_id: "battery-1" }]),
    )
    await instance.check()
    expect(instance.ready).toBe(true)
    expect(emit).not.toHaveBeenCalled()
    instance.finishSetup()
    expect(emit).toHaveBeenCalledTimes(1)
    expect(emit).toHaveBeenCalledWith("LibreSolarBms")
    expect(BatteryRepository.detail).not.toHaveBeenCalled()
    expect(BatteryRepository.events).not.toHaveBeenCalled()
  })

  it("keeps an empty company incomplete and can retry after provisioning", async () => {
    BatteryRepository.list.mockResolvedValueOnce(response([]))
    await instance.check()
    instance.finishSetup()
    expect(instance.ready).toBe(false)
    expect(emit).not.toHaveBeenCalled()
    BatteryRepository.list.mockResolvedValueOnce(
      response([{ id: 1, node_id: "battery-1" }]),
    )
    await instance.check()
    expect(instance.ready).toBe(true)
  })

  it.each([
    [401, "sessionExpired"],
    [403, "forbidden"],
    [503, "requestFailed"],
  ])(
    "clears readiness after status %s and keeps the wizard incomplete",
    async (status, expected) => {
      instance.ready = true
      BatteryRepository.list.mockRejectedValue({ response: { status } })
      await instance.check()
      expect(instance.ready).toBe(false)
      expect(instance.error).toBe(expected)
      instance.finishSetup()
      expect(emit).not.toHaveBeenCalled()
    },
  )

  it("does not allow completion or a duplicate request while the check is pending", async () => {
    let resolve
    BatteryRepository.list.mockReturnValue(
      new Promise((complete) => {
        resolve = complete
      }),
    )
    const pending = instance.check()
    await instance.check()
    instance.finishSetup()
    expect(instance.busy).toBe(true)
    expect(BatteryRepository.list).toHaveBeenCalledTimes(1)
    expect(emit).not.toHaveBeenCalled()
    resolve(response([{ id: 1, node_id: "battery-1" }]))
    await pending
    expect(instance.busy).toBe(false)
    expect(instance.ready).toBe(true)
  })
})
