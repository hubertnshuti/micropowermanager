import Client from "@/repositories/Client/AxiosClient.js"

const resource = "/api/libre-solar-bms/batteries"

export default {
  list(page = 1) {
    return Client.get(resource, { params: { page, per_page: 25 } })
  },
  detail(id) {
    return Client.get(`${resource}/${id}`)
  },
  events(id, page = 1) {
    return Client.get(`${resource}/${id}/events`, {
      params: { page, per_page: 25 },
    })
  },
}
