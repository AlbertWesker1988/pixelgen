<?php

namespace App\Services\Reporting;

use App\Models\Generation;

/**
 * Builds the operator spend/profit report from local records, reconciled against
 * Pixelforge's own usage figures.
 *
 * For each UTC day and each user it reports:
 *   - spend          what users were charged (sum of user_cost)
 *   - provider_cost  what Pixelforge charged us, per our records (sum of provider_cost)
 *   - profit         spend - provider_cost
 *
 * The per-day provider total is then compared to cost_usd from GET /v1/usage. A
 * mismatch means our records and the provider's disagree, e.g. a webhook we never
 * recorded, so the report flags it rather than hiding it.
 *
 * Every figure is summed with bcmath on exact decimal strings; the database never
 * sums money and no float is ever involved.
 */
class SpendReport
{
    private const int SCALE = 18;

    /**
     * @param  iterable<Generation>  $generations  billed (succeeded) generations
     * @param  list<array<string,mixed>>  $usage  the `data` array from GET /v1/usage
     * @return array{days:list<array<string,mixed>>,totals:array<string,mixed>}
     */
    public function build(iterable $generations, array $usage): array
    {
        $reported = $this->reportedByDate($usage);

        /** @var array<string,array<string,mixed>> $days */
        $days = [];

        foreach ($generations as $generation) {
            $date = ($generation->completed_at ?? $generation->created_at)->utc()->format('Y-m-d');
            $userKey = (string) $generation->user_id;

            $days[$date] ??= ['spend' => '0', 'provider_cost' => '0', 'users' => []];
            $days[$date]['users'][$userKey] ??= [
                'name' => $generation->user->name,
                'email' => $generation->user->email,
                'count' => 0,
                'spend' => '0',
                'provider_cost' => '0',
            ];

            $spend = $generation->user_cost ?? '0';
            $cost = $generation->provider_cost ?? '0';

            $days[$date]['spend'] = bcadd($days[$date]['spend'], $spend, self::SCALE);
            $days[$date]['provider_cost'] = bcadd($days[$date]['provider_cost'], $cost, self::SCALE);
            $days[$date]['users'][$userKey]['count']++;
            $days[$date]['users'][$userKey]['spend'] = bcadd($days[$date]['users'][$userKey]['spend'], $spend, self::SCALE);
            $days[$date]['users'][$userKey]['provider_cost'] = bcadd($days[$date]['users'][$userKey]['provider_cost'], $cost, self::SCALE);
        }

        // Surface provider-reported days that have no local generations too, so a
        // "we recorded nothing but were billed" gap is still visible.
        foreach ($reported as $date => $_) {
            $days[$date] ??= ['spend' => '0', 'provider_cost' => '0', 'users' => []];
        }

        krsort($days); // newest day first

        $rows = [];
        $totalSpend = '0';
        $totalProvider = '0';

        foreach ($days as $date => $day) {
            $providerReported = $reported[$date] ?? null;

            $rows[] = [
                'date' => $date,
                'spend' => $this->trim($day['spend']),
                'provider_cost' => $this->trim($day['provider_cost']),
                'provider_reported' => $providerReported === null ? null : $this->trim($providerReported),
                'reconciled' => $providerReported === null
                    ? null
                    : bccomp($day['provider_cost'], $providerReported, self::SCALE) === 0,
                'profit' => $this->trim(bcsub($day['spend'], $day['provider_cost'], self::SCALE)),
                'users' => $this->users($day['users']),
            ];

            $totalSpend = bcadd($totalSpend, $day['spend'], self::SCALE);
            $totalProvider = bcadd($totalProvider, $day['provider_cost'], self::SCALE);
        }

        return [
            'days' => $rows,
            'totals' => [
                'spend' => $this->trim($totalSpend),
                'provider_cost' => $this->trim($totalProvider),
                'profit' => $this->trim(bcsub($totalSpend, $totalProvider, self::SCALE)),
            ],
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $users
     * @return list<array<string,mixed>>
     */
    private function users(array $users): array
    {
        $rows = array_map(fn (array $u): array => [
            'name' => $u['name'],
            'email' => $u['email'],
            'count' => $u['count'],
            'spend' => $this->trim($u['spend']),
            'provider_cost' => $this->trim($u['provider_cost']),
            'profit' => $this->trim(bcsub($u['spend'], $u['provider_cost'], self::SCALE)),
        ], $users);

        usort($rows, fn (array $a, array $b): int => bccomp($b['spend'], $a['spend'], self::SCALE));

        return array_values($rows);
    }

    /**
     * Map each UTC day to Pixelforge's own billed total (cost_usd).
     *
     * @param  list<array<string,mixed>>  $usage
     * @return array<string,string>
     */
    private function reportedByDate(array $usage): array
    {
        $map = [];

        foreach ($usage as $day) {
            if (isset($day['date'], $day['cost_usd'])) {
                $map[(string) $day['date']] = (string) $day['cost_usd'];
            }
        }

        return $map;
    }

    private function trim(string $amount): string
    {
        if (! str_contains($amount, '.')) {
            return $amount;
        }

        $amount = rtrim(rtrim($amount, '0'), '.');

        return $amount === '' || $amount === '-0' ? '0' : $amount;
    }
}
