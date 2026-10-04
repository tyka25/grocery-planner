<?php

namespace App\Http\Controllers;

use App\Models\ScrapeRun;
use App\Services\Sidecar\SidecarRuns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** API for the home-machine sidecar (routes/api.php, SidecarToken middleware). */
class SidecarController extends Controller
{
    public function __construct(private SidecarRuns $runs) {}

    public function work(): JsonResponse
    {
        $work = $this->runs->claimWork();

        return response()->json($work
            ? ['runId' => $work['run']->id, 'lookups' => array_map(
                fn ($l) => array_diff_key($l, ['storeProductId' => true]),
                $work['lookups'],
            )]
            : ['runId' => null, 'lookups' => []]);
    }

    public function snapshots(Request $request, ScrapeRun $run): JsonResponse
    {
        abort_unless($run->status === 'running', 409, "Run {$run->id} is already {$run->status}.");

        $data = $request->validate([
            'snapshots' => ['present', 'array', 'max:2000'],
            'snapshots.*.storeSlug' => ['required', 'string'],
            'snapshots.*.instacartProductId' => ['required', 'string'],
            'snapshots.*.observedAt' => ['required', 'date'],
            'snapshots.*.available' => ['required', 'boolean'],
            'snapshots.*.stockLevel' => ['nullable', 'string'],
            'snapshots.*.price' => ['nullable', 'numeric'],
            'snapshots.*.sizeText' => ['nullable', 'string'],
            'snapshots.*.query' => ['nullable', 'string'],
        ]);

        return response()->json($this->runs->ingest($run, $data['snapshots']));
    }

    public function finish(Request $request, ScrapeRun $run): JsonResponse
    {
        abort_unless($run->status === 'running', 409, "Run {$run->id} is already {$run->status}.");

        $data = $request->validate([
            'status' => ['required', 'in:ok,session_expired,error'],
            'error' => ['nullable', 'string', 'max:5000'],
            'searches' => ['required', 'integer', 'min:0'],
        ]);

        $run = $this->runs->finish($run, $data['status'], $data['error'] ?? null, $data['searches']);

        return response()->json($run->only(['id', 'status', 'error', 'snapshots_saved', 'unknown_products', 'missing']));
    }
}
