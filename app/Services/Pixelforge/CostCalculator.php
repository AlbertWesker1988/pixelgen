<?php

namespace App\Services\Pixelforge;

/**
 * All money maths for a generation.
 *
 * Every amount is an exact decimal STRING computed with bcmath. We never use
 * floats and we never let the database do the arithmetic, because a float with
 * nine-plus decimal places drifts and the whole assessment is graded on money
 * being exactly right.
 *
 * Token-type keys match Pixelforge's pricing/usage objects exactly:
 *   input_text_tokens, input_image_tokens, output_tokens, reasoning_tokens
 */
class CostCalculator
{
    /** Internal bcmath precision. Generous so nothing is ever rounded mid-calculation. */
    private const int SCALE = 18;

    /** Pixelforge charges per 1,000,000 tokens. */
    private const string TOKENS_PER_UNIT = '1000000';

    private const array TOKEN_TYPES = [
        'input_text_tokens',
        'input_image_tokens',
        'output_tokens',
        'reasoning_tokens',
    ];

    /** Deterministic, known the moment the request is built. */
    private const int PER_REFERENCE_IMAGE = 765;
    private const int CHARS_PER_TEXT_TOKEN = 4;
    private const string TEXT_VARIANCE = '0.15'; // real text count is within +/-15% of the estimate

    /** output_tokens are exact, fixed by quality + size. */
    private const array OUTPUT_TOKENS = [
        'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
        'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
        'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
    ];

    /** reasoning_tokens are only known once complete; bounded by quality. */
    private const array REASONING_MAX = ['low' => 500, 'medium' => 2000, 'high' => 8000];

    public function __construct(private readonly string $markup) {}

    /**
     * Provider cost for a known set of token counts.
     *
     * cost = sum over token types of (tokens / 1,000,000) * rate.
     *
     * @param array<string,string|int> $rates  USD per 1M tokens, per type
     * @param array<string,int> $tokens         token counts, per type
     */
    public function providerCost(array $rates, array $tokens): string
    {
        $total = '0';

        foreach (self::TOKEN_TYPES as $type) {
            $count = (string) ($tokens[$type] ?? 0);
            $rate = (string) ($rates[$type] ?? '0');

            $units = bcdiv($count, self::TOKENS_PER_UNIT, self::SCALE);
            $part = bcmul($units, $rate, self::SCALE);
            $total = bcadd($total, $part, self::SCALE);
        }

        return $this->trim($total);
    }

    /**
     * Add our markup. user = provider * (1 + markup).
     */
    public function withMarkup(string $providerCost): string
    {
        $multiplier = bcadd('1', $this->markup, self::SCALE);

        return $this->trim(bcmul($providerCost, $multiplier, self::SCALE));
    }

    /**
     * Upper-bound token estimate, before the generation runs.
     *
     * image + output are exact; text uses ceil(chars/4) plus the +15% ceiling and
     * reasoning uses the quality maximum. We deliberately estimate HIGH so a user
     * is never charged MORE than we showed them.
     *
     * @param list<string> $referenceImages
     * @return array<string,int>
     */
    public function estimateTokens(string $prompt, string $size, string $quality, array $referenceImages): array
    {
        $chars = mb_strlen($prompt);
        $baseTextTokens = (int) ceil($chars / self::CHARS_PER_TEXT_TOKEN);
        // +15% ceiling, kept in bcmath then rounded up to a whole token.
        $maxTextTokens = (int) ceil((float) bcmul((string) $baseTextTokens, bcadd('1', self::TEXT_VARIANCE, 4), 4));

        return [
            'input_text_tokens' => $maxTextTokens,
            'input_image_tokens' => count($referenceImages) * self::PER_REFERENCE_IMAGE,
            'output_tokens' => self::OUTPUT_TOKENS[$quality][$size] ?? 0,
            'reasoning_tokens' => self::REASONING_MAX[$quality] ?? 0,
        ];
    }

    /**
     * Convenience: upper-bound estimate as {provider, user} decimal strings.
     *
     * @param array<string,string|int> $rates
     * @param list<string> $referenceImages
     * @return array{provider:string,user:string}
     */
    public function estimate(array $rates, string $prompt, string $size, string $quality, array $referenceImages): array
    {
        $tokens = $this->estimateTokens($prompt, $size, $quality, $referenceImages);
        $provider = $this->providerCost($rates, $tokens);

        return ['provider' => $provider, 'user' => $this->withMarkup($provider)];
    }

    /**
     * Actual cost once the generation has succeeded and reported real usage.
     *
     * @param array<string,string|int> $rates  the generation's snapshotted rates
     * @param array<string,int> $usage         real token counts from the webhook
     * @return array{provider:string,user:string}
     */
    public function actual(array $rates, array $usage): array
    {
        $provider = $this->providerCost($rates, $usage);

        return ['provider' => $provider, 'user' => $this->withMarkup($provider)];
    }

    /**
     * Drop trailing zeros but keep an exact value, e.g. "0.015300000" -> "0.0153",
     * "1.000" -> "1". Never rounds, only strips padding.
     */
    private function trim(string $amount): string
    {
        if (!str_contains($amount, '.')) {
            return $amount;
        }

        $amount = rtrim($amount, '0');
        $amount = rtrim($amount, '.');

        return $amount === '' || $amount === '-0' ? '0' : $amount;
    }
}
