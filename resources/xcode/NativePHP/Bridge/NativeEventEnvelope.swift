import Foundation

/// Typed event envelope for native-to-PHP dispatch
/// Provides full type safety and Swift 6 Sendable conformance
public struct NativeEventEnvelope: Sendable {
    public let name: String
    public let source: String
    public let sourceId: String
    public let dispatchId: String
    public let sentAt: Int64
    public let payload: JSONValue
    public let meta: JSONValue
    
    /// Factory method to build envelope with auto-generated IDs
    /// - Parameters:
    ///   - name: Full event class name
    ///   - source: Module identifier (e.g., "lifecycle")
    ///   - sourceId: Optional session ID (auto-generated if nil/blank)
    ///   - payload: Event payload as JSONValue
    ///   - meta: Optional metadata
    public static func build(
        name: String,
        source: String,
        sourceId: String? = nil,
        payload: [String: Any],
        meta: [String: Any] = [:]
    ) -> NativeEventEnvelope {
        // Validate sourceId: generate new UUID if nil or blank (matches Android lowercase)
        let resolvedSourceId: String
        if let sid = sourceId, !sid.trimmingCharacters(in: .whitespaces).isEmpty {
            resolvedSourceId = sid.lowercased()
        } else {
            resolvedSourceId = UUID().uuidString.lowercased()
        }
        
        return NativeEventEnvelope(
            name: name,
            source: source,
            sourceId: resolvedSourceId,
            dispatchId: UUID().uuidString.lowercased(),
            sentAt: Int64(Date().timeIntervalSince1970 * 1000),
            payload: convertToJSONValue(payload),
            meta: convertToJSONValue(meta)
        )
    }
    
    /// Converts envelope to JSON string for HTTP transmission
    public func toJsonString() -> String? {
        let dict: [String: Any] = [
            "name": name,
            "source": source,
            "source_id": sourceId,
            "dispatch_id": dispatchId,
            "sent_at": sentAt,
            "payload": payload.toFoundation(),
            "meta": meta.toFoundation()
        ]
        
        guard let data = try? JSONSerialization.data(withJSONObject: dict, options: []) else {
            return nil
        }
        return String(data: data, encoding: .utf8)
    }
    
    // MARK: - Helper
    
    private static func convertToJSONValue(_ dict: [String: Any]) -> JSONValue {
        var result: [String: JSONValue] = [:]
        for (key, value) in dict {
            result[key] = convertValue(value)
        }
        return .object(result)
    }
    
    private static func convertValue(_ value: Any) -> JSONValue {
        switch value {
        case let str as String:
            return .string(str)
        case let bool as Bool:
            return .bool(bool)
        case let num as NSNumber:
            // Check if NSNumber is actually a Bool (Swift Bool bridges to NSNumber for ObjC compatibility)
            if CFBooleanGetTypeID() == CFGetTypeID(num) {
                return .bool(num.boolValue)
            }
            return .number(num.doubleValue)
        case is NSNull:
            return .null
        case let dict as [String: Any]:
            return convertToJSONValue(dict)
        case let arr as [Any]:
            return .array(arr.map { convertValue($0) })
        default:
            return .string(String(describing: value))
        }
    }
}
