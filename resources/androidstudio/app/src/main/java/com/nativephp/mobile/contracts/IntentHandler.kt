package com.nativephp.mobile.contracts

import android.content.Intent

/**
 * Contract for handling Android intents through the IntentPipeline.
 *
 * Implement this interface to create a handler that can intercept and process
 * intents before they reach the core routing logic.
 *
 * Example:
 * ```kotlin
 * class MyHandler : IntentHandler {
 *     override fun handle(intent: Intent, next: () -> Boolean): Boolean {
 *         if (shouldHandle(intent)) {
 *             // Process intent
 *             return true
 *         }
 *         return next()
 *     }
 * }
 * ```
 */
interface IntentHandler {
    /**
     * Handle the given intent.
     *
     * @param intent The intent to handle
     * @param next Function to invoke the next handler in the chain
     * @return true if the intent was handled, false otherwise
     */
    fun handle(intent: Intent, next: () -> Boolean): Boolean
}
