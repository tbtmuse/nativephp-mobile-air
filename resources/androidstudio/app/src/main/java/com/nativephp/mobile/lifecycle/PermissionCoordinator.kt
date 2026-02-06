package com.nativephp.mobile.lifecycle

import android.content.pm.PackageManager
import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.core.app.ActivityCompat
import androidx.fragment.app.FragmentActivity
import java.util.UUID
import java.util.concurrent.ConcurrentHashMap

/**
 * Central coordinator for all permission requests.
 *
 * Manages request code allocation, correlation tracking, and result routing
 * to eliminate collisions and provide deterministic request/result correlation.
 */
object PermissionCoordinator {
    private const val TAG = "PermissionCoordinator"
    private const val TTL_MILLIS = 30000L // 30 seconds

    private val registry = ConcurrentHashMap<Int, RegistryEntry>()
    private val callbacks = ConcurrentHashMap<Int, PermissionCallback>()
    private val requestCodeCounter = java.util.concurrent.atomic.AtomicInteger(10000)
    private val mainHandler = Handler(Looper.getMainLooper())
    private val cleanupHandler = Handler(Looper.getMainLooper())

    data class RegistryEntry(
        val source: String,
        val sourceId: String,
        val permissions: List<String>,
        val timestamp: Long
    )

    fun interface PermissionCallback {
        fun onResult(permission: String, granted: Boolean, sourceId: String, source: String)
    }

    /**
     * Request permissions through the coordinator.
     *
     * @param activity The FragmentActivity to request from
     * @param permissions Array of Android permission strings
     * @param source Plugin identifier (e.g., "camera", "firebase")
     * @param callback Callback invoked for each permission result
     * @return sourceId Unique correlation token for this request
     */
    fun request(
        activity: FragmentActivity,
        permissions: Array<String>,
        source: String,
        callback: PermissionCallback
    ): String {
        val sourceId = generateSourceId()
        val requestCode = nextRequestCode()

        registry[requestCode] = RegistryEntry(
            source = source,
            sourceId = sourceId,
            permissions = permissions.toList(),
            timestamp = System.currentTimeMillis()
        )
        callbacks[requestCode] = callback

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
        val callback = callbacks.remove(requestCode)

        if (entry == null || callback == null) {
            Log.w(TAG, "Received permission result for unknown requestCode=$requestCode (not tracked by coordinator)")
            return
        }

        Log.d(TAG, "Handling permission result: requestCode=$requestCode, source=${entry.source}, sourceId=${entry.sourceId}")

        permissions.forEachIndexed { index, permission ->
            val granted = grantResults.getOrNull(index) == PackageManager.PERMISSION_GRANTED

            mainHandler.post {
                callback.onResult(
                    permission = permission,
                    granted = granted,
                    sourceId = entry.sourceId,
                    source = entry.source
                )
            }

            postLifecycleEvent(permission, granted, requestCode, entry.source, entry.sourceId)
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

    private fun generateSourceId(): String {
        return "req_${UUID.randomUUID().toString().replace("-", "").substring(0, 20)}"
    }

    private fun nextRequestCode(): Int {
        return requestCodeCounter.incrementAndGet()
    }

    private fun scheduleCleanup(requestCode: Int) {
        cleanupHandler.postDelayed({
            if (registry.containsKey(requestCode)) {
                Log.w(TAG, "TTL cleanup: removing abandoned requestCode=$requestCode")
                registry.remove(requestCode)
                callbacks.remove(requestCode)
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
     * @param prefix Optional prefix (default: "req")
     * @return Opaque correlation token
     */
    fun newSourceId(prefix: String = "req"): String {
        return "${prefix}_${UUID.randomUUID().toString().replace("-", "").substring(0, 20)}"
    }
}
