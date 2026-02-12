package com.nativephp.mobile.lifecycle

import android.content.pm.PackageManager
import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.core.app.ActivityCompat
import androidx.fragment.app.FragmentActivity
import kotlin.uuid.Uuid
import kotlin.uuid.ExperimentalUuidApi
import java.util.concurrent.ConcurrentHashMap

/**
 * Central coordinator for all permission requests.
 *
 * Manages request code allocation, correlation tracking, and result routing
 * to eliminate collisions and provide deterministic request/result correlation.
 */
@OptIn(ExperimentalUuidApi::class)
object PermissionCoordinator {
    private const val TAG = "PermissionCoordinator"
    private const val TTL_MILLIS = 30000L // 30 seconds

    private val registry = ConcurrentHashMap<Int, RegistryEntry>()
    private val callbacks = ConcurrentHashMap<Int, OnEachResultCallback>()
    private val completeCallbacks = ConcurrentHashMap<Int, OnCompleteCallback>()
    private val requestCodeCounter = java.util.concurrent.atomic.AtomicInteger(10000)
    private val mainHandler = Handler(Looper.getMainLooper())
    private val cleanupHandler = Handler(Looper.getMainLooper())

    data class RegistryEntry(
        val source: String,
        val sourceId: String,
        val permissions: List<String>,
        val timestamp: Long
    )

    fun interface OnEachResultCallback {
        fun onEachResult(permission: String, granted: Boolean, sourceId: String, source: String)
    }
    
    fun interface OnCompleteCallback {
        fun onComplete(results: List<PermissionResult>, sourceId: String, source: String)
    }
    
    data class PermissionResult(
        val permission: String,
        val granted: Boolean
    )

    /**
     * Request permissions through the coordinator.
     *
     * @param activity The FragmentActivity to request from
     * @param permissions Array of Android permission strings
     * @param source Plugin identifier (e.g., "camera", "firebase")
     * @param onEachResult Optional callback invoked for each permission result (progressive UI)
     * @param onComplete Required callback invoked once with all results when request completes
     * @return sourceId Unique correlation token for this request
     */
    fun request(
        activity: FragmentActivity,
        permissions: Array<String>,
        source: String,
        onEachResult: OnEachResultCallback? = null,
        onComplete: OnCompleteCallback
    ): String {
        val sourceId = Uuid.generateV7().toString()
        val requestCode = requestCodeCounter.incrementAndGet()

        registry[requestCode] = RegistryEntry(
            source = source,
            sourceId = sourceId,
            permissions = permissions.toList(),
            timestamp = System.currentTimeMillis()
        )
        onEachResult?.let { callbacks[requestCode] = it }
        completeCallbacks[requestCode] = onComplete

        scheduleCleanup(requestCode)

        ActivityCompat.requestPermissions(activity, permissions, requestCode)

        Log.d(TAG, "Permission request registered: source=$source, sourceId=$sourceId, requestCode=$requestCode, permissions=${permissions.joinToString()}")

        return sourceId
    }

    /**
     * Handle permission result from Android.
     *
     * Called by MainActivity.onRequestPermissionsResult().
     */
    fun handleResult(
        requestCode: Int,
        permissions: Array<out String>,
        grantResults: IntArray
    ) {
        val entry = registry.remove(requestCode)
        val eachCallback = callbacks.remove(requestCode)
        val completeCallback = completeCallbacks.remove(requestCode)

        if (entry == null || completeCallback == null) {
            Log.w(TAG, "Received permission result for unknown requestCode=$requestCode (not tracked by coordinator)")
            return
        }

        Log.d(TAG, "Handling permission result: requestCode=$requestCode, source=${entry.source}, sourceId=${entry.sourceId}")

        // Build results list for onComplete
        val results = mutableListOf<PermissionResult>()

        permissions.forEachIndexed { index, permission ->
            val granted = grantResults.getOrNull(index) == PackageManager.PERMISSION_GRANTED
            
            results.add(PermissionResult(permission, granted))

            // Call onEachResult if provided (for progressive UI)
            eachCallback?.let { callback ->
                mainHandler.post {
                    callback.onEachResult(
                        permission = permission,
                        granted = granted,
                        sourceId = entry.sourceId,
                        source = entry.source
                    )
                }
            }

            postLifecycleEvent(permission, granted, requestCode, entry.source, entry.sourceId)
        }
        
        // Call onComplete with all results (THE KEY FIX)
        mainHandler.post {
            completeCallback.onComplete(
                results = results,
                sourceId = entry.sourceId,
                source = entry.source
            )
        }
    }

    private fun postLifecycleEvent(
        permission: String,
        granted: Boolean,
        requestCode: Int,
        source: String,
        sourceId: String
    ) {
        val payload = mapOf(
            "permission" to permission,
            "granted" to granted,
            "requestCode" to requestCode,
            "source" to source,
            "sourceId" to sourceId
        )

        NativePHPLifecycle.post(NativePHPLifecycle.Events.ON_PERMISSION_RESULT, payload)
    }

    private fun scheduleCleanup(requestCode: Int) {
        cleanupHandler.postDelayed({
            if (registry.containsKey(requestCode)) {
                Log.w(TAG, "TTL cleanup: removing abandoned requestCode=$requestCode")
                registry.remove(requestCode)
                callbacks.remove(requestCode)
                completeCallbacks.remove(requestCode)
            }
        }, TTL_MILLIS)
    }

    /**
     * Emit a permission result for plugins that handle their own permission requests.
     *
     * This is the "escape hatch" for plugins using ActivityResultContracts or other
     * modern Android APIs that initiate requests outside of core.
     *
     * Does NOT interact with the request registry - this is transport-only.
     *
     * @param permission The Android permission string (e.g., "android.permission.CAMERA")
     * @param granted Whether the permission was granted
     * @param source Plugin identifier (e.g., "camera", "firebase") - REQUIRED for request flows
     * @param sourceId Correlation token - REQUIRED for request flows
     */
    fun emitResult(
        permission: String,
        granted: Boolean,
        source: String? = null,
        sourceId: String? = null
    ) {
        if (source != null && sourceId != null) {
            Log.d(TAG, "Emitting permission result: source=$source, sourceId=$sourceId, permission=$permission, granted=$granted")
        } else {
            Log.d(TAG, "Emitting permission result (uncorrelated): permission=$permission, granted=$granted")
        }

        postLifecycleEvent(permission, granted, -1, source ?: "", sourceId ?: "")
    }

    /**
     * Emit multiple permission results.
     *
     * Helper for plugins using RequestMultiplePermissions ActivityResultContracts.
     * Emits one event per permission, all sharing the same source/sourceId.
     *
     * Does NOT interact with the request registry - this is transport-only.
     *
     * @param results Map of permission string to granted boolean
     * @param source Plugin identifier - REQUIRED for request flows
     * @param sourceId Correlation token - REQUIRED for request flows
     */
    fun emitResults(
        results: Map<String, Boolean>,
        source: String? = null,
        sourceId: String? = null
    ) {
        results.forEach { (permission, granted) ->
            emitResult(permission, granted, source, sourceId)
        }
    }

    /**
     * Generate a new sourceId for permission correlation.
     *
     * Plugins initiating their own requests should use this to ensure
     * consistent ID generation across the system.
     *
     * @return Opaque correlation token (UUID v7)
     */
    fun newSourceId(): String {
        return Uuid.generateV7().toString()
    }
}
