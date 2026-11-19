<?php

namespace Tests\Unit;

use App\Services\Pixelforge\CostCalculator;
use PHPUnit\Framework\TestCase;

class CostCalculatorTest extends TestCase
{
    /** Published rates, USD per 1,000,000 tokens. */
    private const array RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    private function calc(): CostCalculator
    {
        return new CostCalculator('0.25');
    }

    /**
     * Pixelforge's own worked example: high quality, 1536x1024, 400-char prompt,
     * 2 reference images, assuming max text and reasoning. Their docs say the
     * provider total is exactly $0.373875000.
     */
    public function test_provider_cost_matches_pixelforge_worked_example(): void
    {
        $tokens = [
            'input_text_tokens' => 115,   // ceil(400/4)=100, +15% = 115
            'input_image_tokens' => 1530, // 2 * 765
            'output_tokens' => 6450,      // high, 1536x1024
            'reasoning_tokens' => 8000,   // high max
        ];

        $this->assertSame('0.373875', $this->calc()->providerCost(self::RATES, $tokens));
    }

    public function test_markup_is_exact(): void
    {
        // 0.373875 * 1.25 = 0.46734375, exact, no float drift.
        $this->assertSame('0.46734375', $this->calc()->withMarkup('0.373875'));
    }

    public function test_estimate_tokens_are_upper_bound(): void
    {
        $tokens = $this->calc()->estimateTokens(
            prompt: str_repeat('a', 400),
            size: '1536x1024',
            quality: 'high',
            referenceImages: ['https://example.com/a.png', 'https://example.com/b.png'],
        );

        $this->assertSame(115, $tokens['input_text_tokens']);
        $this->assertSame(1530, $tokens['input_image_tokens']);
        $this->assertSame(6450, $tokens['output_tokens']);
        $this->assertSame(8000, $tokens['reasoning_tokens']);
    }

    public function test_zero_tokens_cost_nothing(): void
    {
        $tokens = [
            'input_text_tokens' => 0,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
        ];

        $this->assertSame('0', $this->calc()->providerCost(self::RATES, $tokens));
    }

    public function test_small_fraction_of_a_cent_stays_exact(): void
    {
        // One output token at $40/1M = $0.00004; with 25% markup = $0.00005.
        $tokens = ['output_tokens' => 1];

        $provider = $this->calc()->providerCost(self::RATES, $tokens);
        $this->assertSame('0.00004', $provider);
        $this->assertSame('0.00005', $this->calc()->withMarkup($provider));
    }
}
