<?php

namespace Tests\Feature;

use App\Services\AiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiServiceWakeTest extends TestCase
{
    public function test_waking_service_is_retried_until_it_answers(): void
    {
        config(['services.ai.wake_retries' => 3, 'services.ai.wake_retry_sleep_ms' => 1]);
        Http::fakeSequence('*/api/v1/toolkit/translate')
            ->push('Service waking', 502)
            ->push('Service waking', 503)
            ->push(['status' => 'success', 'data' => ['text' => 'হ্যালো', 'model' => 'hosted:x', 'provider' => 'hosted']]);

        $result = app(AiService::class)->translate('hey', 'en', 'bn');

        $this->assertSame('হ্যালো', $result['data']['text']);
        Http::assertSentCount(3);
    }

    public function test_no_retry_by_default(): void
    {
        config(['services.ai.wake_retries' => 0]);
        Http::fake(['*/api/v1/toolkit/translate' => Http::response('Service waking', 502)]);

        try {
            app(AiService::class)->translate('hey', 'en', 'bn');
        } catch (\Throwable) {
            // the existing error path
        }

        Http::assertSentCount(1);
    }

    public function test_wake_ping_is_sent_at_most_every_two_minutes_and_only_when_enabled(): void
    {
        Cache::flush();
        Http::fake(['*/health' => Http::response(['status' => 'ok'])]);

        config(['services.ai.wake_on_activity' => false]);
        app(AiService::class)->wake();
        Http::assertSentCount(0);

        config(['services.ai.wake_on_activity' => true]);
        app(AiService::class)->wake();
        app(AiService::class)->wake();
        Http::assertSentCount(1);
    }
}
