#pragma once
#include "../c/jas_protocol.h"
#include <cstdint>
#include <span>
#include <stdexcept>
#include <string_view>

namespace jas {
class PacketView {
    jas_packet_view view_{};
public:
    explicit PacketView(std::span<const std::uint8_t> bytes) {
        const int rc = jas_packet_decode_view(bytes.data(), bytes.size(), &view_);
        if (rc != 0) throw std::runtime_error("invalid JAS packet: " + std::to_string(rc));
    }
    std::uint16_t opcode() const noexcept { return view_.opcode; }
    std::uint16_t flags() const noexcept { return view_.flags; }
    std::uint32_t timestamp() const noexcept { return view_.timestamp; }
    std::string_view requestId() const noexcept { return {reinterpret_cast<const char*>(view_.request_id), view_.request_id_length}; }
    std::string_view objectId() const noexcept { return {reinterpret_cast<const char*>(view_.object_id), view_.object_id_length}; }
    std::span<const std::uint8_t> payload() const noexcept { return {view_.payload, view_.payload_length}; }
    bool languagePayloadValid() const noexcept {
        return jas_language_payload_validate(view_.payload, view_.payload_length) == 0;
    }
};
}
