package com.nativephp.mobile.routing

import android.content.Intent
import android.util.Log
import com.nativephp.mobile.contracts.IntentHandler

/**
 * Pipeline for processing Android intents through a chain of handlers.
 * Handlers are invoked in registration order until one returns true.
 */
object IntentPipeline {
    private const val TAG = "IntentPipeline"
    private val handlers = mutableListOf<IntentHandler>()

    fun use(handler: IntentHandler) {
        handlers.add(handler)
        Log.d(TAG, "Added handler: ${handler::class.simpleName}")
    }

    fun dispatch(intent: Intent): Boolean {
        val handlerList = synchronized(this) { handlers.toList() }
        val iterator = handlerList.iterator()

        fun next(): Boolean {
            return if (iterator.hasNext()) {
                try {
                    iterator.next().handle(intent, ::next)
                } catch (e: Exception) {
                    Log.e(TAG, "Handler error", e)
                    next()
                }
            } else {
                false
            }
        }

        return next()
    }

    fun clear() {
        handlers.clear()
    }
}
