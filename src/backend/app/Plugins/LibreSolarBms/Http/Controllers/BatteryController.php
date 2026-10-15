<?php

namespace App\Plugins\LibreSolarBms\Http\Controllers;

use App\Plugins\LibreSolarBms\Exceptions\LibreSolarBmsException;
use App\Plugins\LibreSolarBms\Models\Battery;
use App\Plugins\LibreSolarBms\Services\BatteryService;
use App\Plugins\LibreSolarBms\Services\PayloadValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass as DatabaseRow;

class BatteryController extends Controller {
    public function __construct(private BatteryService $batteries, private PayloadValidator $validator) {}

    public function store(Request $request, string $kind): JsonResponse {
        $json = $request->getContent();
        $payload = $this->validator->validate($kind, $json);

        return response()->json($this->batteries->ingest($kind, $payload, $json));
    }

    public function index(Request $request): JsonResponse {
        [$page, $perPage] = $this->pagination($request);
        $rows = Battery::orderBy('id')->paginate($perPage, ['*'], 'page', $page);
        $data = $rows->getCollection()->map(function (Battery $battery): array {
            $detail = $this->batteries->detail($battery);
            $summary = $detail['summary'];

            return ['id' => $battery->id, 'node_id' => $battery->node_id, 'source' => $battery->source,
                'label' => $battery->label, 'soc' => $summary?->soc, 'soh' => $summary?->soh,
                'cycle_count' => $summary?->cycle_count, 'source_status' => $detail['status']->source_status ?? 'unknown',
                'integration_status' => $detail['integration_status'], 'observed_at' => $detail['metric_observed_at']];
        });

        return response()->json(['data' => $data, 'pagination' => ['page' => $page, 'per_page' => $perPage,
            'total' => $rows->total(), 'last_page' => $rows->lastPage()]]);
    }

    public function show(int $id): JsonResponse {
        return response()->json(['data' => $this->batteries->detail($this->batteries->find($id))]);
    }

    public function events(Request $request, int $id): JsonResponse {
        $battery = $this->batteries->find($id);
        [$page, $perPage] = $this->pagination($request);
        $rows = DB::connection('tenant')->table('libre_solar_bms_events')->where('battery_id', $battery->id)
            ->orderByDesc('observed_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
        $data = $rows->getCollection()->map(fn (DatabaseRow $event): array => [
            'id' => $event->id, 'event' => json_decode($event->payload),
            'received_at' => Carbon::parse($event->received_at)->toISOString(),
        ]);

        return response()->json(['data' => $data, 'pagination' => ['page' => $page, 'per_page' => $perPage,
            'total' => $rows->total(), 'last_page' => $rows->lastPage()]]);
    }

    /** @return array{int, int} */
    private function pagination(Request $request): array {
        foreach (['page', 'per_page'] as $name) {
            $value = $request->query($name);
            if ($value !== null && (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value) || filter_var($value, FILTER_VALIDATE_INT) === false)) {
                throw new LibreSolarBmsException('invalid_pagination', 422);
            }
        }
        $perPage = $request->integer('per_page', 25);
        if ($perPage > 100) {
            throw new LibreSolarBmsException('invalid_pagination', 422);
        }

        $page = $request->integer('page', 1);
        if ($page - 1 > intdiv(PHP_INT_MAX, $perPage)) {
            throw new LibreSolarBmsException('invalid_pagination', 422);
        }

        return [$page, $perPage];
    }
}
