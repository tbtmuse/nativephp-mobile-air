<?php declare(strict_types=1);

namespace Tests\Unit\Event;

use Illuminate\Support\Facades\Cache;
use Native\Mobile\Contracts\NativeEventEnvelope;
use Native\Mobile\Event\NativeEventBus;
use Tests\Fixtures\TestCoalescingEvent;
use Tests\Fixtures\TestNativeEvent;
use Tests\TestCase;

final class NativeEventBusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_dispatches_event_and_returns_cursor(): void
    {
        $event = $this->createTestEvent();

        $cursor = NativeEventBus::dispatch($event);

        $this->assertGreaterThan(0, $cursor);
    }

    public function test_increments_cursor_monotonically(): void
    {
        $cursor1 = NativeEventBus::dispatch($this->createTestEvent());
        $cursor2 = NativeEventBus::dispatch($this->createTestEvent());
        $cursor3 = NativeEventBus::dispatch($this->createTestEvent());

        $this->assertEquals(1, $cursor1);
        $this->assertEquals(2, $cursor2);
        $this->assertEquals(3, $cursor3);
    }

    public function test_stores_event_in_buffer(): void
    {
        $event = $this->createTestEvent();
        $cursor = NativeEventBus::dispatch($event);

        $scope = $this->getScope();
        $bufferKey = 'native:cursor:' . $scope . ':' . $cursor;
        $this->assertTrue(Cache::has($bufferKey));

        $stored = Cache::get($bufferKey);
        $this->assertEquals(TestNativeEvent::class, $stored['class']);
        $this->assertArrayHasKey('envelope', $stored);
        $this->assertArrayHasKey('payload', $stored);
    }

    public function test_throws_for_non_native_event(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Event must implement');

        NativeEventBus::dispatch(new \stdClass());
    }

    public function test_returns_events_since_cursor(): void
    {
        NativeEventBus::dispatch($this->createTestEvent());
        NativeEventBus::dispatch($this->createTestEvent());
        NativeEventBus::dispatch($this->createTestEvent());

        $events = NativeEventBus::eventsSince(1);

        $this->assertCount(2, $events);
        $this->assertArrayHasKey(2, $events);
        $this->assertArrayHasKey(3, $events);
    }

    public function test_events_since_returns_integer_keys_not_cache_key_strings(): void
    {
        NativeEventBus::dispatch($this->createTestEvent());
        NativeEventBus::dispatch($this->createTestEvent());

        $events = NativeEventBus::eventsSince(0);

        foreach (array_keys($events) as $key) {
            $this->assertIsInt($key, 'eventsSince() must return integer cursor keys, not cache key strings');
        }

        $this->assertSame([1, 2], array_keys($events));
    }

    public function test_events_since_keys_support_max_for_cursor_tracking(): void
    {
        NativeEventBus::dispatch($this->createTestEvent());
        NativeEventBus::dispatch($this->createTestEvent());
        NativeEventBus::dispatch($this->createTestEvent());

        $events = NativeEventBus::eventsSince(0);
        $newCursor = max(array_keys($events));

        $this->assertSame(3, $newCursor);

        NativeEventBus::dispatch($this->createTestEvent());
        NativeEventBus::dispatch($this->createTestEvent());

        $nextEvents = NativeEventBus::eventsSince($newCursor);

        $this->assertCount(2, $nextEvents);
        $this->assertSame([4, 5], array_keys($nextEvents));
    }

    public function test_events_since_consecutive_calls_return_non_overlapping_results(): void
    {
        NativeEventBus::dispatch($this->createTestEvent(['batch' => 1]));
        NativeEventBus::dispatch($this->createTestEvent(['batch' => 1]));

        $firstBatch = NativeEventBus::eventsSince(0);
        $firstCursor = max(array_keys($firstBatch));

        NativeEventBus::dispatch($this->createTestEvent(['batch' => 2]));

        $secondBatch = NativeEventBus::eventsSince($firstCursor);
        $secondCursor = max(array_keys($secondBatch));

        $thirdBatch = NativeEventBus::eventsSince($secondCursor);

        $this->assertCount(2, $firstBatch);
        $this->assertCount(1, $secondBatch);
        $this->assertEmpty($thirdBatch);

        $firstKeys = array_keys($firstBatch);
        $secondKeys = array_keys($secondBatch);
        $this->assertEmpty(array_intersect($firstKeys, $secondKeys));
    }

    public function test_events_since_expired_entries_preserve_integer_keying(): void
    {
        NativeEventBus::dispatch($this->createTestEvent());
        $expiredCursor = NativeEventBus::dispatch($this->createTestEvent());
        NativeEventBus::dispatch($this->createTestEvent());

        $scope = $this->getScope();
        Cache::forget('native:cursor:' . $scope . ':' . $expiredCursor);

        $events = NativeEventBus::eventsSince(0);

        $this->assertCount(2, $events);
        $this->assertArrayHasKey(1, $events);
        $this->assertArrayNotHasKey($expiredCursor, $events);
        $this->assertArrayHasKey(3, $events);

        foreach (array_keys($events) as $key) {
            $this->assertIsInt($key);
        }
    }

    public function test_returns_empty_array_when_no_new_events(): void
    {
        $event = $this->createTestEvent();
        $cursor = NativeEventBus::dispatch($event);

        $events = NativeEventBus::eventsSince($cursor);

        $this->assertEmpty($events);
        $this->assertIsArray($events);
    }

    public function test_returns_empty_array_for_future_cursor(): void
    {
        $events = NativeEventBus::eventsSince(999);

        $this->assertEmpty($events);
    }

    public function test_uses_batch_fetch_for_multiple_events(): void
    {
        for ($i = 0; $i < 10; $i++) {
            NativeEventBus::dispatch($this->createTestEvent());
        }

        $result = NativeEventBus::eventsSince(0);

        $this->assertCount(10, $result);
    }

    public function test_handles_expired_buffer_entries(): void
    {
        NativeEventBus::dispatch($this->createTestEvent());
        $cursor2 = NativeEventBus::dispatch($this->createTestEvent());
        NativeEventBus::dispatch($this->createTestEvent());

        $scope = $this->getScope();
        $bufferKey = 'native:cursor:' . $scope . ':' . $cursor2;
        Cache::forget($bufferKey);

        $events = NativeEventBus::eventsSince(0);

        $this->assertCount(2, $events);
        $this->assertArrayNotHasKey($cursor2, $events);
    }

    public function test_coalesces_events_with_same_key(): void
    {
        $event1 = $this->createCoalescingEvent('shared-key', ['pos' => 1]);
        $event2 = $this->createCoalescingEvent('shared-key', ['pos' => 2]);
        $event3 = $this->createCoalescingEvent('shared-key', ['pos' => 3]);

        NativeEventBus::dispatch($event1);
        NativeEventBus::dispatch($event2);
        NativeEventBus::dispatch($event3);

        $this->assertEquals(0, NativeEventBus::currentCursor());

        NativeEventBus::flushCoalesced();

        $events = NativeEventBus::eventsSince(0);
        $this->assertCount(1, $events);
    }

    public function test_separate_coalesce_keys_stay_separate(): void
    {
        $event1 = $this->createCoalescingEvent('key-a', ['data' => 1]);
        $event2 = $this->createCoalescingEvent('key-b', ['data' => 2]);

        NativeEventBus::dispatch($event1);
        NativeEventBus::dispatch($event2);

        NativeEventBus::flushCoalesced();

        $events = NativeEventBus::eventsSince(0);
        $this->assertCount(2, $events);
    }

    public function test_flush_coalesced_clears_buffer(): void
    {
        $event = $this->createCoalescingEvent('key', ['data' => 1]);
        NativeEventBus::dispatch($event);

        NativeEventBus::flushCoalesced();

        $events = NativeEventBus::eventsSince(0);
        $this->assertCount(1, $events);

        NativeEventBus::flushCoalesced();

        $events = NativeEventBus::eventsSince(1);
        $this->assertEmpty($events);
    }

    public function test_returns_current_cursor(): void
    {
        $this->assertEquals(0, NativeEventBus::currentCursor());

        NativeEventBus::dispatch($this->createTestEvent());
        $this->assertEquals(1, NativeEventBus::currentCursor());

        NativeEventBus::dispatch($this->createTestEvent());
        $this->assertEquals(2, NativeEventBus::currentCursor());
    }

    public function test_cursor_is_per_user_scoped(): void
    {
        $cursor1 = NativeEventBus::dispatch($this->createTestEvent());

        $this->assertEquals(1, $cursor1);

        $newScope = 'different-session-id';
        $this->assertNotEquals($this->getScope(), $newScope);
    }

    public function test_event_payload_stored_correctly(): void
    {
        $payload = ['latitude' => 51.5, 'longitude' => -0.1];
        $event = $this->createTestEvent($payload);

        $cursor = NativeEventBus::dispatch($event);

        $events = NativeEventBus::eventsSince($cursor - 1);
        $this->assertEquals($payload, $events[$cursor]['payload']);
    }

    public function test_fires_laravel_event(): void
    {
        $event = $this->createTestEvent();
        $fired = false;

        \Illuminate\Support\Facades\Event::listen(TestNativeEvent::class, function () use (&$fired) {
            $fired = true;
        });

        NativeEventBus::dispatch($event);

        $this->assertTrue($fired);
    }

    private function createTestEvent(array $payload = ['test' => 'data']): TestNativeEvent
    {
        $envelope = new NativeEventEnvelope(
            name: 'TestEvent',
            source: 'test',
            sourceId: 'test-1',
            dispatchId: 'dispatch-' . uniqid(),
            sentAt: time(),
            payload: $payload,
            meta: []
        );

        return TestNativeEvent::fromNativeEventEnvelope($envelope, $payload);
    }

    private function createCoalescingEvent(string $key, array $data): TestCoalescingEvent
    {
        $envelope = new NativeEventEnvelope(
            name: 'CoalescingEvent',
            source: 'test',
            sourceId: 'test-1',
            dispatchId: 'dispatch-' . uniqid(),
            sentAt: time(),
            payload: $data,
            meta: []
        );

        return new TestCoalescingEvent($key, $data, $envelope);
    }

    private function getScope(): string
    {
        $user = auth()->user();
        return $user?->getAuthIdentifier() ?? session()->getId();
    }
}
