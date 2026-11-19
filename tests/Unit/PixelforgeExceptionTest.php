<?php

namespace Tests\Unit;

use App\Services\Pixelforge\PixelforgeException;
use PHPUnit\Framework\TestCase;

class PixelforgeExceptionTest extends TestCase
{
    public function test_rate_limit_and_overload_are_transient(): void
    {
        $rateLimit = new PixelforgeException(429, 'rate_limit_exceeded', 'rate_limit_error', 'slow down');
        $overloaded = new PixelforgeException(503, 'overloaded', 'api_error', 'busy');

        $this->assertFalse($rateLimit->isTerminal());
        $this->assertFalse($overloaded->isTerminal());
    }

    public function test_bad_input_and_auth_are_terminal(): void
    {
        $policy = new PixelforgeException(400, 'content_policy_violation', 'invalid_request_error', 'no');
        $validation = new PixelforgeException(422, 'validation_failed', 'invalid_request_error', 'bad');
        $auth = new PixelforgeException(401, 'invalid_api_key', 'authentication_error', 'nope');

        $this->assertTrue($policy->isTerminal());
        $this->assertTrue($validation->isTerminal());
        $this->assertTrue($auth->isTerminal());
    }
}
