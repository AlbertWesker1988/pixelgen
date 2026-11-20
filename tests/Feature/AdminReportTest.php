<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Services\Reporting\SpendReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    private function billedGeneration(User $user, string $date, string $providerCost, string $userCost): Generation
    {
        return $user->generations()->create([
            'idempotency_key' => (string) Str::uuid(),
            'status' => GenerationStatus::Succeeded,
            'prompt' => 'a cat',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'provider_cost' => $providerCost,
            'user_cost' => $userCost,
            'completed_at' => "{$date} 12:00:00",
        ]);
    }

    public function test_a_non_admin_cannot_open_the_report(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.report'))
            ->assertForbidden();
    }

    public function test_an_admin_can_open_the_report(): void
    {
        Http::fake(['*/usage*' => Http::response(['data' => []])]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.report'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Report'));
    }

    public function test_report_sums_spend_provider_and_profit_per_day(): void
    {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);

        $this->billedGeneration($alice, '2026-11-19', '0.1', '0.125');
        $this->billedGeneration($alice, '2026-11-19', '0.05', '0.0625');
        $this->billedGeneration($bob, '2026-11-19', '0.2', '0.25');

        $usage = [
            // Our provider sum for the day is 0.1 + 0.05 + 0.2 = 0.35; it matches.
            ['date' => '2026-11-19', 'cost_usd' => '0.350000000'],
        ];

        $report = app(SpendReport::class)->build(Generation::with('user')->get(), $usage);

        $this->assertCount(1, $report['days']);
        $day = $report['days'][0];

        $this->assertSame('2026-11-19', $day['date']);
        $this->assertSame('0.4375', $day['spend']);        // 0.125 + 0.0625 + 0.25
        $this->assertSame('0.35', $day['provider_cost']);  // 0.1 + 0.05 + 0.2
        $this->assertSame('0.0875', $day['profit']);       // 0.4375 - 0.35
        $this->assertTrue($day['reconciled']);

        // Users sorted by spend desc: Bob (0.25) before Alice (0.1875).
        $this->assertSame('Bob', $day['users'][0]['name']);
        $this->assertSame('0.25', $day['users'][0]['spend']);
        $this->assertSame('Alice', $day['users'][1]['name']);
        $this->assertSame('0.1875', $day['users'][1]['spend']);
        $this->assertSame(2, $day['users'][1]['count']);

        $this->assertSame('0.4375', $report['totals']['spend']);
        $this->assertSame('0.0875', $report['totals']['profit']);
    }

    public function test_report_flags_a_mismatch_against_provider_usage(): void
    {
        $user = User::factory()->create();
        $this->billedGeneration($user, '2026-11-19', '0.1', '0.125');

        // Pixelforge says it billed more than our records show: a lost webhook.
        $usage = [['date' => '2026-11-19', 'cost_usd' => '0.200000000']];

        $report = app(SpendReport::class)->build(Generation::with('user')->get(), $usage);

        $this->assertFalse($report['days'][0]['reconciled']);
        $this->assertSame('0.2', $report['days'][0]['provider_reported']);
    }
}
