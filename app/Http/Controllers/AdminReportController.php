<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Reporting\SpendReport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operator-only spend/profit report. Gated by the `access-admin` ability.
 */
class AdminReportController extends Controller
{
    public function __invoke(PixelforgeClient $client, SpendReport $report): Response
    {
        // Only billed generations carry costs; failed ones were never charged.
        $generations = Generation::query()
            ->with('user')
            ->where('status', GenerationStatus::Succeeded)
            ->get();

        [$start, $end] = $this->dateRange($generations);

        return Inertia::render('Admin/Report', [
            'report' => $report->build($generations, $this->usage($client, $start, $end)),
            'range' => ['start' => $start, 'end' => $end],
        ]);
    }

    /**
     * Pixelforge's per-day usage for the range, or an empty set if the call
     * fails: the report still renders from local records, just without the
     * reconciliation column.
     *
     * @return list<array<string,mixed>>
     */
    private function usage(PixelforgeClient $client, string $start, string $end): array
    {
        try {
            return $client->getUsage($start, $end)['data'] ?? [];
        } catch (PixelforgeException|ConnectionException) {
            return [];
        }
    }

    /**
     * The UTC span covering our billed generations, defaulting to today when
     * there are none yet.
     *
     * @param  iterable<Generation>  $generations
     * @return array{0:string,1:string}
     */
    private function dateRange(iterable $generations): array
    {
        $dates = [];

        foreach ($generations as $generation) {
            $dates[] = ($generation->completed_at ?? $generation->created_at)->utc()->format('Y-m-d');
        }

        if ($dates === []) {
            $today = Carbon::now('UTC')->format('Y-m-d');

            return [$today, $today];
        }

        return [min($dates), max($dates)];
    }
}
