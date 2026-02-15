<?php declare(strict_types=1);

namespace Native\Mobile\Event;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Session\Session;
use Livewire\Component;
use Native\Mobile\Attributes\OnNative;
use ReflectionAttribute;
use ReflectionClass;

final class NativeEventSubscriptions
{
    private const TTL_SECONDS = 300;
    private const KEY_PREFIX = 'native:subscriptions';

    /** @var array<class-string, array<string, list<string>>> */
    private static array $reflectionCache = [];

    public function __construct(
        private Repository $cache,
        private Guard $auth,
        private Session $session
    ) {}

    public function register(Component $component): void
    {
        $componentId = $component->getId();
        $cacheKey = $this->key($componentId);

        if ($this->cache->has($cacheKey)) {
            return;
        }

        $events = $this->reflectSubscriptions($component);

        $this->cache->put($cacheKey, [
            'events' => $events,
            'cursor' => NativeEventBus::currentCursor(),
        ], now()->addSeconds(self::TTL_SECONDS));
    }

    public function getPendingDispatches(Component $component): array
    {
        NativeEventBus::flushCoalesced();

        $componentId = $component->getId();
        $cacheKey = $this->key($componentId);
        $subscription = $this->cache->get($cacheKey);

        if ($subscription === null) {
            return [];
        }

        $lastCursor = $subscription['cursor'];
        $events = NativeEventBus::eventsSince($lastCursor);

        if (empty($events)) {
            return [];
        }

        $subscribedEvents = $subscription['events'];
        $dispatches = [];
        $newCursor = $lastCursor;

        foreach ($events as $cursor => $event) {
            $newCursor = max($newCursor, $cursor);
            $eventClass = $event['class'];
            $prefixedEvent = 'native:' . $eventClass;

            if (isset($subscribedEvents[$prefixedEvent])) {
                foreach ($subscribedEvents[$prefixedEvent] as $method) {
                    $dispatches[] = [
                        'event' => $prefixedEvent,
                        'method' => $method,
                        'payload' => $event['payload'],
                    ];
                }
            }
        }

        $this->cache->put($cacheKey, [
            'events' => $subscribedEvents,
            'cursor' => $newCursor,
        ], now()->addSeconds(self::TTL_SECONDS));

        return $dispatches;
    }

    public function clear(string $componentId): void
    {
        $this->cache->forget($this->key($componentId));
    }

    private function reflectSubscriptions(Component $component): array
    {
        $class = $component::class;

        if (isset(self::$reflectionCache[$class])) {
            return self::$reflectionCache[$class];
        }

        $reflection = new ReflectionClass($component);
        $events = [];

        foreach ($reflection->getMethods() as $method) {
            $attributes = $method->getAttributes(OnNative::class, ReflectionAttribute::IS_INSTANCEOF);
            foreach ($attributes as $attribute) {
                $instance = $attribute->newInstance();
                $events[$instance->event][] = $method->getName();
            }
        }

        self::$reflectionCache[$class] = $events;

        return $events;
    }

    private function key(string $componentId): string
    {
        return self::KEY_PREFIX . ':' . $componentId;
    }
}
