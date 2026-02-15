<?php declare(strict_types=1);

namespace Native\Mobile\Event;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Native\Mobile\Contracts\Coalesces;
use Native\Mobile\Contracts\NativeEvent;

final class NativeEventBus
{
    private const CURSOR_TTL_SECONDS = 120;
    private const BUFFER_KEY_PREFIX = 'native:event';
    private const CURSOR_KEY_PREFIX = 'native:cursor';

    /** @var array<string, list<Coalesces>> */
    private static array $coalesceBuffer = [];

    public static function dispatch(object $event): int
    {
        if (!$event instanceof NativeEvent) {
            throw new \InvalidArgumentException('Event must implement ' . NativeEvent::class);
        }

        if ($event instanceof Coalesces) {
            return self::dispatchCoalescing($event);
        }

        return self::dispatchImmediate($event);
    }

    private static function dispatchCoalescing(NativeEvent&Coalesces $event): int
    {
        $key = $event->coalesceKey();

        if (!isset(self::$coalesceBuffer[$key])) {
            self::$coalesceBuffer[$key] = [];
        }

        if (!empty(self::$coalesceBuffer[$key])) {
            $last = self::$coalesceBuffer[$key][array_key_last(self::$coalesceBuffer[$key])];
            $merged = $last->coalesceWith($event);
            self::$coalesceBuffer[$key][array_key_last(self::$coalesceBuffer[$key])] = $merged;
        } else {
            self::$coalesceBuffer[$key][] = $event;
        }

        return 0;
    }

    private static function dispatchImmediate(NativeEvent $event): int
    {
        $cursorKey = self::cursorKey();
        $cursor = cache()->increment($cursorKey);

        cache()->put(
            self::bufferKey($cursor),
            [
                'class' => $event::class,
                'envelope' => $event->getEnvelope()->toArray(),
                'payload' => $event->getEnvelope()->payload,
            ],
            now()->addSeconds(self::CURSOR_TTL_SECONDS)
        );

        event($event);

        return $cursor;
    }

    public static function flushCoalesced(): void
    {
        foreach (self::$coalesceBuffer as $events) {
            foreach ($events as $event) {
                self::dispatchImmediate($event);
            }
        }

        self::$coalesceBuffer = [];
    }

    public static function eventsSince(int $lastCursor): array
    {
        $currentCursor = (int) cache()->get(self::cursorKey(), 0);

        if ($currentCursor <= $lastCursor) {
            return [];
        }

        $keyToCursor = [];
        for ($cursor = $lastCursor + 1; $cursor <= $currentCursor; $cursor++) {
            $keyToCursor[self::bufferKey($cursor)] = $cursor;
        }

        $rawResults = cache()->many(array_keys($keyToCursor));

        $events = [];
        foreach ($rawResults as $cacheKey => $event) {
            if ($event !== null) {
                $events[$keyToCursor[$cacheKey]] = $event;
            }
        }

        return $events;
    }

    public static function currentCursor(): int
    {
        return (int) cache()->get(self::cursorKey(), 0);
    }

    private static function cursorKey(): string
    {
        $user = Auth::user();
        $scope = $user?->getAuthIdentifier() ?? Session::getId();
        return self::CURSOR_KEY_PREFIX . ':' . $scope;
    }

    private static function bufferKey(int $cursor): string
    {
        return self::cursorKey() . ':' . $cursor;
    }
}
