import BatteryRepository from "../repositories/BatteryRepository.js"

function isObject(value) {
  return value !== null && typeof value === "object" && !Array.isArray(value)
}

function collection(response, validRow) {
  const body = response?.data
  const pagination = body?.pagination
  if (
    !Array.isArray(body?.data) ||
    !pagination ||
    ![pagination.page, pagination.per_page, pagination.last_page].every(
      (value) => Number.isInteger(value) && value > 0,
    ) ||
    !Number.isInteger(pagination.total) ||
    pagination.total < 0 ||
    !body.data.every(validRow)
  ) {
    throw new Error("Invalid battery collection response")
  }
  return body
}

export function batteryErrorKey(error) {
  const status = error.response?.status
  if (status === 401) return "sessionExpired"
  if (status === 403) {
    return error.response?.data?.code === "plugin_disabled"
      ? "pluginDisabled"
      : "forbidden"
  }
  if (status === 404) return "notFound"
  return "requestFailed"
}

export function createBatteryService(repository = BatteryRepository) {
  return {
    async list(page) {
      return collection(
        await repository.list(page),
        (row) =>
          isObject(row) &&
          Number.isInteger(row.id) &&
          row.id > 0 &&
          typeof row.node_id === "string" &&
          row.node_id.length > 0,
      )
    },
    async detail(id) {
      const response = await repository.detail(id)
      const detail = response?.data?.data
      if (
        !detail ||
        typeof detail !== "object" ||
        Array.isArray(detail) ||
        !Number.isInteger(detail.id) ||
        detail.id <= 0 ||
        String(detail.id) !== String(id) ||
        ["summary", "status", "attributes"].some(
          (field) => detail[field] != null && !isObject(detail[field]),
        )
      ) {
        throw new Error("Invalid battery detail response")
      }
      return detail
    },
    async events(id, page) {
      return collection(
        await repository.events(id, page),
        (row) =>
          isObject(row) &&
          isObject(row.event) &&
          ["event_id", "observed_at", "kind"].every(
            (field) =>
              typeof row.event[field] === "string" &&
              row.event[field].length > 0,
          ),
      )
    },
  }
}
