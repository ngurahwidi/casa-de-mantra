<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Verifies a Cloudflare Turnstile token when the widget is configured.
 *
 * Without a secret key (local development, tests, or a deployment that has not set up
 * Turnstile yet) the check is skipped so the booking form stays usable. Production
 * configures both keys through services.turnstile.
 */
class Turnstile implements ValidationRule
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $secret = config('services.turnstile.secret_key');

        if (blank($secret)) {
            return;
        }

        if (! is_string($value) || trim($value) === '') {
            $fail('Please confirm you are human.');

            return;
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->post(self::VERIFY_URL, [
                    'secret' => $secret,
                    'response' => $value,
                    'remoteip' => request()->ip(),
                ]);
        } catch (ConnectionException) {
            $fail('We could not verify that you are human. Please try again.');

            return;
        }

        if (! $response->successful() || $response->json('success') !== true) {
            $fail('We could not verify that you are human. Please try again.');
        }
    }
}
