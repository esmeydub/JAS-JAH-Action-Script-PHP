#include "jas_protocol.h"
#include <stdio.h>
#include <stdlib.h>

int main(int argc, char **argv) {
    if (argc != 2) return 2;
    FILE *f = fopen(argv[1], "rb"); if (!f) return 3;
    fseek(f, 0, SEEK_END); long n = ftell(f); rewind(f);
    if (n <= 0) { fclose(f); return 4; }
    uint8_t *buf = malloc((size_t)n); if (!buf) { fclose(f); return 5; }
    if (fread(buf, 1, (size_t)n, f) != (size_t)n) { free(buf); fclose(f); return 6; }
    fclose(f);
    jas_packet_view p; int rc = jas_packet_decode_view(buf, (size_t)n, &p);
    if (rc == 0) printf("opcode=%u request=%.*s object=%.*s payload=%u\n", p.opcode, p.request_id_length, p.request_id, p.object_id_length, p.object_id, p.payload_length);
    free(buf); return rc == 0 ? 0 : 7;
}
