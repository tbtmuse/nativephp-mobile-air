package com.nativephp.mobile.contracts.permissions

import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONObject
import org.json.JSONArray
import kotlin.uuid.ExperimentalUuidApi
import kotlin.uuid.Uuid

/**
 * Data class representing a single permission result
 */
data class PermissionResult(
    val permission: String,
    val granted: Boolean,
    val meta: Map<String, Any>? = null
)

@OptIn(ExperimentalUuidApi::class)
object PermissionResultDispatcher {

    private const val TAG = "PermissionResultDispatch"
    private const val EVENT_NAME = "Native\\Mobile\\Events\\Permissions\\PermissionResult"
    private val mainHandler = Handler(Looper.getMainLooper())

    /**
     * Dispatch batched permission results from a single request.
     * 
     * @param results List of permission results (can be 1 or many)
     * @param meta Optional request-level metadata
     */
    fun dispatch(
        activity: FragmentActivity,
        source: String,
        sourceId: String,
        results: List<PermissionResult>,
        meta: Map<String, Any>? = null
    ) {
        val dispatchId = Uuid.generateV7().toString()
        
        Log.d(TAG, "dispatch() id=$dispatchId source=$source permissions=${results.size}")
        
        val payload = buildPayload(source, sourceId, results, meta, dispatchId)

        mainHandler.post {
            try {
                NativeActionCoordinator.dispatchEvent(activity, EVENT_NAME, payload)
                Log.d(TAG, "dispatched id=$dispatchId")
            } catch (e: Exception) {
                Log.e(TAG, "dispatch() FAILED id=$dispatchId: ${e.message}")
                e.printStackTrace()
            }
        }
    }

    private fun buildPayload(
        source: String,
        sourceId: String,
        results: List<PermissionResult>,
        meta: Map<String, Any>?,
        dispatchId: String
    ): String {
        return JSONObject().apply {
            put("name", EVENT_NAME)
            put("source", source)
            put("source_id", sourceId)
            put("dispatch_id", dispatchId)
            put("sent_at", System.currentTimeMillis())
            put("payload", JSONObject().apply {
                put("results", JSONArray().apply {
                    results.forEach { result ->
                        put(JSONObject().apply {
                            put("permission", result.permission)
                            put("status", if (result.granted) "granted" else "denied")
                            put("meta", JSONObject().apply {
                                result.meta?.forEach { (key, value) ->
                                    when (value) {
                                        is String -> put(key, value)
                                        is Number -> put(key, value)
                                        is Boolean -> put(key, value)
                                        else -> put(key, value.toString())
                                    }
                                }
                            })
                        })
                    }
                })
            })
            put("meta", JSONObject().apply {
                meta?.forEach { (key, value) ->
                    when (value) {
                        is String -> put(key, value)
                        is Number -> put(key, value)
                        is Boolean -> put(key, value)
                        else -> put(key, value.toString())
                    }
                }
            })
        }.toString()
    }
}
