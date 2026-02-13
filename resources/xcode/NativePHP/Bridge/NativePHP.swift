final class LaravelBridge {
    static let shared = LaravelBridge()

    /// Closure for dispatching events to Laravel
    /// - Parameters:
    ///   - event: The PHP event class name
    ///   - envelopeJson: JSON string of the NativeEventEnvelope
    var send: ((_ event: String, _ envelopeJson: String) -> Void)?
}
