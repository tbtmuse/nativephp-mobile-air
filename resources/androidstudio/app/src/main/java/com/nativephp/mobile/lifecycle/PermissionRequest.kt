package com.nativephp.mobile.lifecycle

/**
 * Pure data class representing a permission request.
 *
 * Contains no callbacks - callbacks are managed internally by PermissionCoordinator.
 *
 * @property source Plugin identifier (e.g., "camera", "firebase")
 * @property sourceId Unique correlation token for this request instance
 * @property permissions List of Android permission strings being requested
 */
data class PermissionRequest(
    val source: String,
    val sourceId: String,
    val permissions: List<String>
)
