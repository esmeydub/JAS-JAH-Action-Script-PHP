#include "jas_protocol.h"
#include <string.h>

static uint16_t read_u16_be(const uint8_t *p) { return (uint16_t)(((uint16_t)p[0] << 8) | p[1]); }
static uint32_t read_u32_be(const uint8_t *p) { return ((uint32_t)p[0] << 24) | ((uint32_t)p[1] << 16) | ((uint32_t)p[2] << 8) | p[3]; }

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
