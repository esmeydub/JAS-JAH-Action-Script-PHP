#include "jas_protocol.h"
#include <string.h>

static uint16_t read_u16_be(const uint8_t *p) { return (uint16_t)(((uint16_t)p[0] << 8) | p[1]); }
static uint32_t read_u32_be(const uint8_t *p) { return ((uint32_t)p[0] << 24) | ((uint32_t)p[1] << 16) | ((uint32_t)p[2] << 8) | p[3]; }

static int jas_language_value_validate(const uint8_t *data, size_t length, size_t *offset, unsigned depth) {
    if (!data || !offset || depth > 16u || *offset > length || length - *offset < 5u) return -1;
    const uint8_t type = data[*offset];
    const uint32_t value_length = read_u32_be(data + *offset + 1u);
    *offset += 5u;
    if ((size_t)value_length > length - *offset) return -2;
    const size_t end = *offset + (size_t)value_length;
    if (type <= 2u) { if (value_length != 0u) return -3; return 0; }
    if (type == 3u) { if (value_length != 4u) return -4; *offset = end; return 0; }
    if (type == 4u) { *offset = end; return 0; }
    if ((type != 5u && type != 6u) || value_length < 2u) return -5;
    const uint16_t count = read_u16_be(data + *offset); *offset += 2u;
    if (count > 4096u) return -6;
    for (uint16_t i = 0; i < count; ++i) {
        if (type == 6u) {
            if (*offset >= end) return -7;
            const uint8_t key_length = data[(*offset)++];
            if (key_length == 0u || (size_t)key_length > end - *offset) return -8;
            *offset += key_length;
        }
        if (jas_language_value_validate(data, end, offset, depth + 1u) != 0) return -9;
    }
    return *offset == end ? 0 : -10;
}

int jas_language_payload_validate(const uint8_t *data, size_t length) {
    if (!data || length < 10u || length > 8388608u || memcmp(data, "JASL", 4) != 0 || data[4] != 1u) return -1;
    size_t offset = 5u;
    if (data[offset] != 6u || jas_language_value_validate(data, length, &offset, 0u) != 0) return -2;
    return offset == length ? 0 : -3;
}

int jas_packet_decode_view(const uint8_t *data, size_t length, jas_packet_view *out) {
    if (!data || !out || length < JAS_HEADER_SIZE + JAS_SIGNATURE_SIZE) return -1;
    if (memcmp(data, JAS_MAGIC, 4) != 0 || data[4] != JAS_VERSION) return -2;

    const uint16_t opcode = read_u16_be(data + 6);
    const uint16_t flags = read_u16_be(data + 8);
    const uint32_t timestamp = read_u32_be(data + 10);
    const uint32_t payload_length = read_u32_be(data + 14);
    const uint8_t request_length = data[18];
    const uint8_t object_length = data[19];
    const size_t body_length = JAS_HEADER_SIZE + (size_t)request_length + (size_t)object_length + (size_t)payload_length;
    if (body_length > SIZE_MAX - JAS_SIGNATURE_SIZE || length != body_length + JAS_SIGNATURE_SIZE) return -3;

    size_t offset = JAS_HEADER_SIZE;
    out->opcode = opcode; out->flags = flags; out->timestamp = timestamp;
    out->request_id = data + offset; out->request_id_length = request_length; offset += request_length;
    out->object_id = data + offset; out->object_id_length = object_length; offset += object_length;
    out->payload = data + offset; out->payload_length = payload_length;
    out->signature = data + body_length;
    return 0;
}
