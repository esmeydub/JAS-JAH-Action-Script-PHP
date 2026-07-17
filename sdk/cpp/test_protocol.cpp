#include "jas_protocol.hpp"
#include <cstdint>
#include <fstream>
#include <iostream>
#include <iterator>
#include <vector>

int main(int argc, char **argv) {
    if (argc != 2) return 2;
    std::ifstream input(argv[1], std::ios::binary);
    if (!input) return 3;
    std::vector<std::uint8_t> bytes(
        (std::istreambuf_iterator<char>(input)), std::istreambuf_iterator<char>()
    );
    try {
        const jas::PacketView packet(bytes);
        if (packet.opcode() != JAS_OPCODE_LANGUAGE_INITIALIZE || !packet.languagePayloadValid()) return 4;
        std::cout << "JAS C++ LANGUAGE CONTRACT: PASS\n";
        return 0;
    } catch (const std::exception &) {
        return 5;
    }
}
