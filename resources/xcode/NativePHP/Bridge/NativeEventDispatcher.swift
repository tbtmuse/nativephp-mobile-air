import Foundation
import WebKit

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
        
        // Get WebView via SharedWebView.shared
        guard let coordinator = SharedWebView.shared.coordinator,
              let webView = coordinator.webView else {
            print("⚠️ Coordinator/WebView not available, skipping dispatch for: \(event)")
            return
        }
        
        // Send to PHP
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
        
        // Inject JS for Livewire
        let jsEvent = """
        (function() {
            const eventEnvelope = \(envelopeJson);
            const detail = { 
                name: "\(event)", 
                event: "\(event)", 
                payload: eventEnvelope.payload || {} 
            };
            document.dispatchEvent(new CustomEvent("native-event", { detail }));
        })();
        """
        webView.evaluateJavaScript(jsEvent)
    }
}
