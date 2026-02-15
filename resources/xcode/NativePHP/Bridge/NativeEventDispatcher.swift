import Foundation

/// Static event dispatcher for plugin convenience
/// Mirrors Android's NativeActionCoordinator.dispatchEvent()
@MainActor
public enum NativeEventDispatcher {
    
    /// Dispatch an event to PHP using a typed envelope
    /// - Parameters:
    ///   - event: The PHP event class name (e.g., "Tbtmuse\\NativephpLifecycle\\Events\\ApplicationActive")
    ///   - envelope: The envelope to dispatch
    public static func dispatch(event: String, envelope: NativeEventEnvelope) {
        guard let envelopeJson = envelope.toJsonString() else {
            print("❌ Failed to serialize envelope for: \(event)")
            return
        }
        
        // Direct HTTP dispatch to PHP backend only
        // UI updates must come from PHP via wire:poll or push (SSE/WebSocket)
        let request = RequestData(
            method: "POST",
            uri: "php://127.0.0.1/_native/api/events",
            data: envelopeJson,
            query: "",
            headers: [
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest"
            ]
        )
        
        _ = NativePHPApp.laravel(request: request)
    }
}
