#ifndef JAS_PROTOCOL_H
#define JAS_PROTOCOL_H

#include <stdint.h>
#include <stddef.h>

#ifdef __cplusplus
extern "C" {
#endif

#define JAS_MAGIC "JASB"
#define JAS_VERSION 2u
#define JAS_HEADER_SIZE 36u
#define JAS_SIGNATURE_SIZE 32u

#define JAS_OPCODE_PING 1u
#define JAS_OPCODE_ACTION_EXECUTE 100u
#define JAS_OPCODE_OBJECT_EVENT 110u
#define JAS_OPCODE_OBJECT_STATE_GET 111u
#define JAS_OPCODE_OBJECT_STATE_PATCH 112u
#define JAS_OPCODE_WORKER_REGISTER 200u
#define JAS_OPCODE_WORKER_HEARTBEAT 201u
#define JAS_OPCODE_TELEMETRY_METRICS 500u
#define JAS_OPCODE_TELEMETRY_TRACES 501u
#define JAS_OPCODE_LANGUAGE_INITIALIZE 600u
#define JAS_OPCODE_LANGUAGE_INITIALIZED 601u
#define JAS_OPCODE_LANGUAGE_DOCUMENT_OPEN 610u
#define JAS_OPCODE_LANGUAGE_DOCUMENT_CHANGE 611u
#define JAS_OPCODE_LANGUAGE_DOCUMENT_CLOSE 612u
#define JAS_OPCODE_LANGUAGE_HOVER 620u
#define JAS_OPCODE_LANGUAGE_DEFINITION 621u
#define JAS_OPCODE_LANGUAGE_REFERENCES 622u
#define JAS_OPCODE_LANGUAGE_PREPARE_RENAME 623u
#define JAS_OPCODE_LANGUAGE_RENAME 624u
#define JAS_OPCODE_LANGUAGE_DIAGNOSTICS 630u
#define JAS_OPCODE_LANGUAGE_SHUTDOWN 640u
#define JAS_OPCODE_LANGUAGE_EXIT 641u
#define JAS_OPCODE_LANGUAGE_RESPONSE 690u
#define JAS_OPCODE_LANGUAGE_ERROR 691u
#define JAS_OPCODE_RESULT 900u
#define JAS_OPCODE_ERROR 901u

typedef struct jas_packet_view {
    uint16_t opcode;
    uint16_t flags;
    uint32_t timestamp;
    const uint8_t *request_id;
    uint8_t request_id_length;
    const uint8_t *object_id;
    uint8_t object_id_length;
    const uint8_t *payload;
    uint32_t payload_length;
    const uint8_t *signature;
} jas_packet_view;

/* Decodifica estructura y límites. La firma SALK debe verificarse antes de ejecutar. */
int jas_packet_decode_view(const uint8_t *data, size_t length, jas_packet_view *out);

/* Valida el árbol TLV JASL sin interpretar semántica ni asignar memoria. */
int jas_language_payload_validate(const uint8_t *data, size_t length);

#ifdef __cplusplus
}
#endif

#endif
