<?php declare(strict_types=1);

namespace Native\Mobile\Events\Permissions;

/**
 * Permission status enumeration.
 *
 * Standardizes permission states across platforms (Android/iOS).
 * Phase 1 supports: GRANTED, DENIED
 * Future phases add: DENIED_PERMANENTLY, NOT_DETERMINED, PROVISIONAL, EPHEMERAL, RESTRICTED
 */
enum PermissionStatus: string
{
    /** Permission approved */
    case GRANTED = 'granted';

    /** Permission denied (temporary, can ask again) */
    case DENIED = 'denied';

    /** Denied with "don't ask again" (Android) - Phase 2 */
    case DENIED_PERMANENTLY = 'denied_permanently';

    /** Initial state, not yet requested (iOS) - Phase 2 */
    case NOT_DETERMINED = 'not_determined';

    /** Provisional authorization (iOS 12+) - Phase 2 */
    case PROVISIONAL = 'provisional';

    /** Time-limited authorization (iOS App Clips) - Phase 2 */
    case EPHEMERAL = 'ephemeral';

    /** Restricted by system/policy (iOS) - Phase 2 */
    case RESTRICTED = 'restricted';
}
