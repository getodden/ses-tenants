<?php

namespace Odden\SesTenants\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Odden\SesTenants\Exceptions\SnsException;
use Odden\SesTenants\Sns\SesEventProcessor;
use Odden\SesTenants\Sns\SnsVerifier;
use Throwable;

/**
 * Delivery events: SES publishes bounces, complaints and deliveries to an SNS topic, and SNS posts them here. Nothing in a
 * request is read until its signature is proved (see SnsVerifier). Anything proved and handled, ignored or already seen
 * answers 200, so SNS stops retrying; only an untrusted request gets a refusal.
 */
class SesEventsController
{
    public function __invoke(Request $request, SnsVerifier $verifier, SesEventProcessor $processor): JsonResponse
    {
        abort_if((array) config('ses-tenants.sns.topics', []) === [], 404);

        $message = json_decode($request->getContent(), true);

        if (! is_array($message)) {
            return response()->json(['error' => 'Not a message.'], 400);
        }

        try {
            $verifier->verify($message);
        } catch (SnsException $e) {
            Log::warning('Refused an SNS message: '.$e->getMessage());

            return response()->json(['error' => 'Not verified.'], 403);
        }

        // Cache::add is false when this message id was already handled: SNS delivers at least once.
        $marker = 'ses-tenants:sns-message:'.hash('sha256', (string) $message['MessageId']);

        if (! Cache::add($marker, true, 7 * 86400)) {
            return response()->json(['status' => 'duplicate']);
        }

        try {
            return match ($message['Type']) {
                'SubscriptionConfirmation' => self::confirm($message),
                'Notification' => response()->json(['status' => $this->notification($processor, (string) ($message['Message'] ?? ''))]),
                default => response()->json(['status' => 'ignored']),
            };
        } catch (Throwable $e) {
            // Answer 500 so SNS sends it again, and have it counted as new when it does: left marked, the retry would be answered
            // "duplicate" and a bounce or complaint lost to a hiccup would never reach the suppressions, usage or reputation pause.
            Cache::forget($marker);

            throw $e;
        }
    }

    /**
     * Confirm the topic's subscription by visiting the address SNS gave, but only if it is an Amazon SNS address.
     *
     * @param  array<string, mixed>  $message
     */
    public static function confirm(array $message): JsonResponse
    {
        $url = (string) ($message['SubscribeURL'] ?? '');

        if (! SnsVerifier::isSnsUrl($url)) {
            return response()->json(['status' => 'ignored']);
        }

        try {
            Http::timeout(5)->get($url);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['error' => 'Could not confirm.'], 502);
        }

        return response()->json(['status' => 'confirmed']);
    }

    private function notification(SesEventProcessor $processor, string $body): string
    {
        $event = json_decode($body, true);

        return is_array($event) ? $processor->handle($event) : 'ignored';
    }
}
