<?php declare(strict_types=1);

namespace Native\Mobile\Http\Controllers;

use Throwable;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Illuminate\Support\Facades\Log;
use Native\Mobile\Contracts\NativeEvent;
use Native\Mobile\Contracts\NativeEventEnvelope;
use Native\Mobile\Event\NativeEventBus;

class DispatchEventFromAppController
{
    public function __invoke(Request $request)
    {
        $eventClass = $request->get('name');
        $dispatchId = $request->get('dispatch_id');

        if (!$dispatchId) {
            Log::error('[PHP] ❌ MISSING_DISPATCH_ID', [
                'name' => $eventClass,
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'all_keys' => array_keys($request->all()),
                'body' => $request->all(),
                'content_type' => $request->header('Content-Type'),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'dispatch_id is required',
            ], 400);
        }

        try {
            $envelope = NativeEventEnvelope::fromArray($request->all());
        } catch (InvalidArgumentException $e) {
            Log::error('[PHP] ❌ INVALID_ENVELOPE', [
                'dispatch_id' => $dispatchId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'dispatch_id' => $dispatchId,
                'error' => $e->getMessage(),
            ], 400);
        }

        Log::debug('[PHP] 📥 ENTRYPOINT', [
            'dispatch_id' => $envelope->dispatchId,
            'source' => $envelope->source,
            'source_id' => $envelope->sourceId,
            'name' => $envelope->name,
            'sent_at' => $envelope->sentAt,
            'time' => time(),
        ]);

        if (!class_exists($eventClass)) {
            Log::error('[PHP] ❌ CLASS_NOT_FOUND', [
                'dispatch_id' => $dispatchId,
                'name' => $eventClass,
            ]);

            return response()->json([
                'success' => false,
                'dispatch_id' => $dispatchId,
                'error' => 'Event class not found',
            ], 404);
        }

        if (!in_array(NativeEvent::class, class_implements($eventClass) ?: [], true)) {
            Log::error('[PHP] ❌ INVALID_EVENT_CONTRACT', [
                'dispatch_id' => $dispatchId,
                'name' => $eventClass,
                'error' => 'Event must implement NativeEvent',
            ]);

            return response()->json([
                'success' => false,
                'dispatch_id' => $dispatchId,
                'error' => 'Event must implement NativeEvent',
            ], 400);
        }

        try {
            $eventInstance = $eventClass::fromNativeEventEnvelope($envelope, $envelope->payload);
        } catch (Throwable $e) {
            Log::error('[PHP] ❌ EVENT_INSTANTIATION_FAILED', [
                'dispatch_id' => $dispatchId,
                'name' => $eventClass,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'dispatch_id' => $dispatchId,
                'error' => 'Failed to instantiate event: ' . $e->getMessage(),
            ], 500);
        }

        try {
            $cursor = NativeEventBus::dispatch($eventInstance);

            Log::debug('[PHP] ✅ DISPATCHED', [
                'dispatch_id' => $dispatchId,
                'cursor' => $cursor,
            ]);

            return response()->json([
                'success' => true,
                'dispatch_id' => $dispatchId,
                'cursor' => $cursor,
            ]);
        } catch (Throwable $e) {
            Log::error('[PHP] ❌ EVENT_DISPATCH_FAILED', [
                'dispatch_id' => $dispatchId,
                'name' => $eventClass,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'dispatch_id' => $dispatchId,
                'error' => 'Failed to dispatch event: ' . $e->getMessage(),
            ], 500);
        }
    }
}
