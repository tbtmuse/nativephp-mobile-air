package com.nativephp.mobile.contracts.permissions

import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONObject

object PermissionResultDispatcher {

    private const val TAG = "PermissionResultDispatch"
    private const val EVENT_NAME = "Native\\Mobile\\Events\\Permissions\\PermissionResult"
    private val mainHandler = Handler(Looper.getMainLooper())

    fun dispatch(
        activity: FragmentActivity,
        source: String,
        sourceId: String,
        permission: String,
        granted: Boolean,
        meta: Map<String, Any>? = null
    ) {
        Log.d(TAG, "dispatch() source=$source, permission=$permission, granted=$granted")
        
        val payload = buildPayload(source, sourceId, permission, granted, meta)

        mainHandler.post {
            try {
                NativeActionCoordinator.dispatchEvent(activity, EVENT_NAME, payload)
            } catch (e: Exception) {
                Log.e(TAG, "dispatch() FAILED: ${e.message}")
                e.printStackTrace()
            }
        }
    }

    private fun buildPayload(
        source: String,
        sourceId: String,
        permission: String,
        granted: Boolean,
        meta: Map<String, Any>?
    ): String {
        return JSONObject().apply {
            put("source", source)
            put("sourceId", sourceId)
            put("permission", permission)
            put("status", if (granted) "granted" else "denied")
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
