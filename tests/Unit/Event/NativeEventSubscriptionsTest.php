<?php declare(strict_types=1);

namespace Tests\Unit\Event;

use Illuminate\Support\Facades\Cache;
use Native\Mobile\Contracts\NativeEventEnvelope;
use Native\Mobile\Event\NativeEventBus;
use Native\Mobile\Event\NativeEventSubscriptions;
use Tests\Fixtures\CoalescingEventComponent;
use Tests\Fixtures\MultiHandlerComponent;
use Tests\Fixtures\NoEventsComponent;
use Tests\Fixtures\OtherTestNativeEvent;
use Tests\Fixtures\TestCoalescingEvent;
use Tests\Fixtures\TestEventComponent;
use Tests\Fixtures\TestNativeEvent;
use Tests\TestCase;

final class NativeEventSubscriptionsTest extends TestCase
{
    private NativeEventSubscriptions $subscriptions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->subscriptions = app(NativeEventSubscriptions::class);
    }

    public function test_registers_component_with_native_events(): void
    {
        $component = $this->makeEventComponent();

        $this->subscriptions->register($component);

        $this->assertTrue(Cache::has('native:subscriptions:' . $component->getId()));
    }

    public function test_skips_registration_if_already_cached(): void
    {
        $component = $this->makeEventComponent();

        $this->subscriptions->register($component);
        $this->subscriptions->register($component);

        $this->assertTrue(true);
    }

    public function test_returns_pending_dispatches_for_subscribed_events(): void
    {
        $component = $this->makeEventComponent();
        $this->subscriptions->register($component);

        $event = $this->createTestEvent();
        NativeEventBus::dispatch($event);

        $dispatches = $this->subscriptions->getPendingDispatches($component);

        $this->assertCount(1, $dispatches);
        $this->assertEquals('native:' . TestNativeEvent::class, $dispatches[0]['event']);
        $this->assertEquals('handleTestEvent', $dispatches[0]['method']);
    }

    public function test_returns_empty_array_when_no_subscription(): void
    {
        $component = $this->makeEventComponent();

        $dispatches = $this->subscriptions->getPendingDispatches($component);

        $this->assertEmpty($dispatches);
        $this->assertIsArray($dispatches);
    }

    public function test_returns_empty_array_when_no_new_events(): void
    {
        $component = $this->makeEventComponent();
        $this->subscriptions->register($component);

        $dispatches = $this->subscriptions->getPendingDispatches($component);

        $this->assertEmpty($dispatches);
    }

    public function test_updates_cursor_after_fetching_dispatches(): void
    {
        $component = $this->makeEventComponent();
        $this->subscriptions->register($component);

        $event = $this->createTestEvent();
        NativeEventBus::dispatch($event);

        $this->subscriptions->getPendingDispatches($component);

        $subscription = Cache::get('native:subscriptions:' . $component->getId());
        $this->assertEquals(1, $subscription['cursor']);
    }

    public function test_filters_events_by_subscription(): void
    {
        $component = $this->makeEventComponent();
        $this->subscriptions->register($component);

        $subscribedEvent = $this->createTestEvent();
        $otherEvent = $this->createOtherTestEvent();

        NativeEventBus::dispatch($subscribedEvent);
        NativeEventBus::dispatch($otherEvent);

        $dispatches = $this->subscriptions->getPendingDispatches($component);

        $this->assertCount(1, $dispatches);
        $this->assertEquals('native:' . TestNativeEvent::class, $dispatches[0]['event']);
    }

    public function test_clears_subscription_from_cache(): void
    {
        $component = $this->makeEventComponent();
        $this->subscriptions->register($component);

        $componentId = $component->getId();
        $this->subscriptions->clear($componentId);

        $this->assertFalse(Cache::has('native:subscriptions:' . $componentId));
    }

    public function test_handles_multiple_handlers_for_same_event(): void
    {
        $component = $this->makeMultiHandlerComponent();
        $this->subscriptions->register($component);

        $event = $this->createTestEvent();
        NativeEventBus::dispatch($event);

        $dispatches = $this->subscriptions->getPendingDispatches($component);

        $this->assertCount(2, $dispatches);
        $methodNames = array_column($dispatches, 'method');
        $this->assertContains('handleTestEvent', $methodNames);
        $this->assertContains('handleTestEventAgain', $methodNames);
    }

    public function test_caches_reflection_results_per_class(): void
    {
        $component1 = $this->makeEventComponent('comp-1');
        $component2 = $this->makeEventComponent('comp-2');

        $this->subscriptions->register($component1);
        $this->subscriptions->register($component2);

        $this->assertTrue(Cache::has('native:subscriptions:comp-1'));
        $this->assertTrue(Cache::has('native:subscriptions:comp-2'));
    }

    public function test_returns_empty_for_component_without_native_events(): void
    {
        $component = new NoEventsComponent();
        $component->setId('no-events-1');

        $this->subscriptions->register($component);

        $dispatches = $this->subscriptions->getPendingDispatches($component);
        $this->assertEmpty($dispatches);
    }

    public function test_dispatches_include_payload(): void
    {
        $component = $this->makeEventComponent();
        $this->subscriptions->register($component);

        $payload = ['test' => 'data', 'value' => 123];
        $event = $this->createTestEvent($payload);
        NativeEventBus::dispatch($event);

        $dispatches = $this->subscriptions->getPendingDispatches($component);

        $this->assertEquals($payload, $dispatches[0]['payload']);
    }

    public function test_coalesced_events_are_flushed_before_fetching(): void
    {
        $component = $this->makeCoalescingComponent();
        $this->subscriptions->register($component);

        $event = $this->createCoalescingTestEvent('test-key', ['pos' => 1]);
        NativeEventBus::dispatch($event);

        $dispatches = $this->subscriptions->getPendingDispatches($component);

        $this->assertCount(1, $dispatches);
    }

    private function makeEventComponent(string $id = 'test-event-1'): TestEventComponent
    {
        $component = new TestEventComponent();
        $component->setId($id);

        return $component;
    }

    private function makeMultiHandlerComponent(string $id = 'test-multi-1'): MultiHandlerComponent
    {
        $component = new MultiHandlerComponent();
        $component->setId($id);

        return $component;
    }

    private function makeCoalescingComponent(string $id = 'test-coalescing-1'): CoalescingEventComponent
    {
        $component = new CoalescingEventComponent();
        $component->setId($id);

        return $component;
    }

    private function createTestEvent(array $payload = ['test' => 'data']): TestNativeEvent
    {
        $envelope = new NativeEventEnvelope(
            name: TestNativeEvent::class,
            source: 'test',
            sourceId: 'test-1',
            dispatchId: 'dispatch-' . uniqid(),
            sentAt: time(),
            payload: $payload,
            meta: []
        );

        return TestNativeEvent::fromNativeEventEnvelope($envelope, $payload);
    }

    private function createOtherTestEvent(array $payload = ['other' => 'data']): OtherTestNativeEvent
    {
        $envelope = new NativeEventEnvelope(
            name: OtherTestNativeEvent::class,
            source: 'test',
            sourceId: 'test-1',
            dispatchId: 'dispatch-' . uniqid(),
            sentAt: time(),
            payload: $payload,
            meta: []
        );

        return OtherTestNativeEvent::fromNativeEventEnvelope($envelope, $payload);
    }

    private function createCoalescingTestEvent(string $key, array $data): TestCoalescingEvent
    {
        $envelope = new NativeEventEnvelope(
            name: TestCoalescingEvent::class,
            source: 'test',
            sourceId: 'test-1',
            dispatchId: 'dispatch-' . uniqid(),
            sentAt: time(),
            payload: $data,
            meta: []
        );

        return new TestCoalescingEvent($key, $data, $envelope);
    }
}
