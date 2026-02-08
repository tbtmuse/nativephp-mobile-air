package com.nativephp.mobile.plugin

import android.content.Intent
import android.util.Log

/**
 * Registry for plugins to intercept and handle Android intents.
 *
 * Plugins can register handlers to claim intents before they are processed
 * as deep links. This enables proper routing for:
 * - Notification clicks (with PendingIntent identity)
 * - OAuth callbacks
 * - File picker results
 * - Share intents
 * - Other plugin-specific intent handling
 *
 * Usage in plugins:
 * ```kotlin
 * class NotificationHandler : PluginIntentRegistry.IntentHandler {
 *     override fun handle(intent: Intent): Boolean {
 *         if (intent.getStringExtra("type") == "notification") {
 *             // Process notification
 *             return true  // Claimed
 *         }
 *         return false  // Pass to next handler
 *     }
 * }
 *
 * // Register
 * PluginIntentRegistry.register(NotificationHandler())
 * ```
 */
object PluginIntentRegistry {
    private const val TAG = "PluginIntentRegistry"

    /**
     * Interface for intent handlers.
     * Implement this to intercept intents in MainActivity.
     */
    interface IntentHandler {
        /**
         * Attempt to handle the intent.
         *
         * @param intent The intent to handle
         * @return true if the intent was handled and should not be processed further,
         *         false to pass to next handler or deep link routing
         */
        fun handle(intent: Intent): Boolean
    }

    private val handlers = mutableListOf<IntentHandler>()

    /**
     * Register an intent handler.
     * Handlers are checked in registration order (first-come-first-served).
     *
     * @param handler The handler to register
     */
    @Synchronized
    fun register(handler: IntentHandler) {
        handlers.add(handler)
        Log.d(TAG, "Registered intent handler: ${handler.javaClass.simpleName}")
    }

    /**
     * Unregister an intent handler.
     *
     * @param handler The handler to unregister
     */
    @Synchronized
    fun unregister(handler: IntentHandler) {
        handlers.remove(handler)
        Log.d(TAG, "Unregistered intent handler: ${handler.javaClass.simpleName}")
    }

    /**
     * Dispatch an intent to registered handlers.
     * Checks handlers in order until one claims the intent.
     *
     * @param intent The intent to dispatch
     * @return true if the intent was handled by a plugin,
     *         false if it should be processed as a deep link
     */
    fun dispatch(intent: Intent): Boolean {
        val handlerList = synchronized(this) {
            handlers.toList()
        }

        for (handler in handlerList) {
            try {
                if (handler.handle(intent)) {
                    Log.d(TAG, "Intent handled by: ${handler.javaClass.simpleName}")
                    return true
                }
            } catch (e: Exception) {
                Log.e(TAG, "Error in handler ${handler.javaClass.simpleName}", e)
            }
        }

        return false
    }

    /**
     * Clear all registered handlers.
     * Call this during cleanup (e.g., in MainActivity.onDestroy).
     */
    @Synchronized
    fun clear() {
        handlers.clear()
        Log.d(TAG, "Cleared all intent handlers")
    }

    /**
     * Get the number of registered handlers.
     * Useful for debugging.
     */
    @Synchronized
    fun handlerCount(): Int = handlers.size
}
