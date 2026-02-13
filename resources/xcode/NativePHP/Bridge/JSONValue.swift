import Foundation

/// Type-safe JSON value representation
/// Provides Sendable conformance for Swift 6 strict concurrency
public enum JSONValue: Sendable {
    case string(String)
    case number(Double)
    case bool(Bool)
    case null
    case array([JSONValue])
    case object([String: JSONValue])
}

extension JSONValue {
    /// Converts JSONValue to Foundation-compatible types for serialization
    func toFoundation() -> Any {
        switch self {
        case .string(let value):
            return value
        case .number(let value):
            return value
        case .bool(let value):
            return value
        case .null:
            return NSNull()
        case .array(let values):
            return values.map { $0.toFoundation() }
        case .object(let dict):
            return dict.mapValues { $0.toFoundation() }
        }
    }
}
