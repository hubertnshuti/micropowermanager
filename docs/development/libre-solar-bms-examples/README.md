# Review examples

Gateway source: `66a4fbcf1bdda6dd008de45f8f95455408678f45`.
These files are software fixtures, not endpoint or integration evidence.
The three unmodified API examples are copied from `tests/fixtures/v1/api/`.
Files containing `.proposed` illustrate UI states using the existing detail shape.
They require Hubert and Lucide review and do not establish new API meanings.
The fault values come from `valid/fault.telemetry.json`.
The unknown example preserves null faults rather than declaring the battery healthy.
The disconnected example preserves last known metrics and original timestamps.
Evaluate all examples at the fixture clock, 2026-10-08T14:00:00Z.
Advancing the clock by 150 seconds must make integration stale.
The plugin-disabled example follows the documented error envelope.

Copied source checksums:

- `battery-list.json`: `c048aed52e683a033b274ef27a218b6fd57e57e0fe321d571f2fe7353ea4da76`
- `battery-detail.json`: `1c0dbdcc87199c467db3c94a072279138993bee7362639535cd366e5a26af564`
- `event-list.json`: `870cbc2b36e7956737c58fdda376915c1695b7c6a75a087c0069b145a85af60b`
