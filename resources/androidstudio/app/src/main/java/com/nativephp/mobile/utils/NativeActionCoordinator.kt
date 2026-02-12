package com.nativephp.mobile.utils

import android.util.Log
import android.webkit.WebView
import androidx.activity.result.contract.ActivityResultContracts
import androidx.fragment.app.Fragment
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.network.PHPRequest
import com.nativephp.mobile.network.WebViewManager
import org.json.JSONObject
import kotlin.uuid.ExperimentalUuidApi
import kotlin.uuid.Uuid

interface WebViewProvider {
    fun getWebView(): WebView
}

@OptIn(ExperimentalUuidApi::class)
class NativeActionCoordinator : Fragment() {

    // File picker launcher
    private val filePicker =
        registerForActivityResult(ActivityResultContracts.OpenDocument()) { uri ->
            uri ?: return@registerForActivityResult
            val payload = JSONObject().apply {
                put("uri", uri.toString())
            }
            dispatch("file:chosen", buildEnvelope("file:chosen", "file", null, payload))
        }

    fun launchFilePicker(mime: String = "*/*") {
        filePicker.launch(arrayOf(mime))
    }

    fun launchAlert(title: String, message: String, buttons: Array<String>, id: String?, eventClass: String?) {
        Log.d("NativeActionCoordinator", "🚨 launchAlert called with title: '$title', message: '$message', buttons: ${buttons.contentToString()}, id: '$id', eventClass: '$eventClass'")

        val context = requireContext()

        // Use default event class if not provided
        val finalEventClass = eventClass ?: "Native\\Mobile\\Events\\Alert\\ButtonPressed"

        // Use NativeActions to show the alert with callback
        NativeActions.showAlert(context, title, message, buttons) { index, label ->
            Log.d("NativeActionCoordinator", "🔘 Alert button clicked: index=$index, label='$label'")

            // Create payload for the ButtonPressed event with optional id
            val payload = JSONObject().apply {
                put("index", index)
                put("label", label)
                if (id != null) {
                    put("id", id)
                }
            }

            // Dispatch the event back to PHP with custom event class
            dispatch(finalEventClass, buildEnvelope(finalEventClass, "alert", id, payload))
        }
    }

    private fun dispatch(event: String, envelopeJson: String) {
            Log.d("JSFUNC", "native:$event");
            Log.d("JSFUNC", "$envelopeJson");
            val eventForJs = event.replace("\\", "\\\\")
            val js = """
                (function () {
                    const eventEnvelope = $envelopeJson;

                    const detail = { name: "$eventForJs", event: "$eventForJs", payload: eventEnvelope.payload || {} };

                    document.dispatchEvent(new CustomEvent("native-event", { detail }));

                    if (window.Livewire && typeof window.Livewire.dispatch === 'function') {
                        window.Livewire.dispatch("native:$eventForJs", { event: eventEnvelope });
                    }
                })();
            """.trimIndent()

            Log.d("NativeActionCoordinator", "📢 Dispatching JS event: $event")

            (activity as? WebViewProvider)?.getWebView()?.evaluateJavascript(js, null)

            // Dispatch to Laravel's global event system directly via PHPBridge,
            // bypassing the WebView fetch interceptor to avoid the lastPostData race condition
            // where Livewire's fetch and native event fetch overwrite each other's POST body.
            Thread {
                try {
                    val phpBridge = WebViewManager.shared?.phpBridge
                    if (phpBridge != null) {
                        val request = PHPRequest(
                            url = "/_native/api/events",
                            method = "POST",
                            body = envelopeJson,
                            headers = mapOf(
                                "Content-Type" to "application/json",
                                "X-Requested-With" to "XMLHttpRequest"
                            )
                        )
                        val response = phpBridge.handleLaravelRequest(request)
                        Log.d("NativeActionCoordinator", "✅ Direct PHP dispatch success for: $event")
                    } else {
                        Log.w("NativeActionCoordinator", "⚠️ PHPBridge not available, skipping direct dispatch for: $event")
                    }
                } catch (e: Exception) {
                    Log.e("NativeActionCoordinator", "❌ Direct PHP dispatch failed for: $event — ${e.message}")
                }
            }.start()
        }


    companion object {
        fun install(activity: FragmentActivity): NativeActionCoordinator =
            activity.supportFragmentManager.findFragmentByTag("NativeActionCoordinator") as? NativeActionCoordinator
                ?: NativeActionCoordinator().also {
                    activity.supportFragmentManager.beginTransaction()
                        .add(it, "NativeActionCoordinator")
                        .commitNow()
                }

        /**
         * Dispatch an event to PHP from anywhere in the app
         * This is a helper method for activities/fragments that need to dispatch events
         */
        fun dispatchEvent(activity: FragmentActivity, event: String, envelopeJson: String) {
            Log.d("NativeActionCoordinator", "📢 Static dispatch event: $event")
            val coordinator = install(activity)
            coordinator.dispatch(event, envelopeJson)
        }
    }

    private fun buildEnvelope(name: String, source: String, sourceId: String?, payload: JSONObject): String {
        val resolvedSourceId = sourceId?.takeIf { it.isNotBlank() } ?: Uuid.generateV7().toString()
        val envelope = JSONObject().apply {
            put("name", name)
            put("source", source)
            put("source_id", resolvedSourceId)
            put("dispatch_id", Uuid.generateV7().toString())
            put("sent_at", System.currentTimeMillis())
            put("payload", payload)
            put("meta", JSONObject())
        }
        return envelope.toString()
    }
}
