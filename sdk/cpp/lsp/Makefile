CXX ?= g++
CXXFLAGS ?= -std=c++20 -O2 -Wall -Wextra -Wpedantic -Werror
LDLIBS ?= -lcrypto -pthread

.PHONY: all test clean

all: jas-lsp-bridge

jas-lsp-bridge: jas_lsp_bridge.cpp
	$(CXX) $(CXXFLAGS) $< -o $@ $(LDLIBS)

test: jas-lsp-bridge
	$(CXX) $(CXXFLAGS) -DJAS_LSP_REQUEST_TIMEOUT_MS=200 jas_lsp_bridge.cpp -o /tmp/jas-lsp-bridge-timeout-test $(LDLIBS)
	$(CXX) $(CXXFLAGS) -DJAS_LSP_MESSAGE_RATE_PER_SECOND=1 -DJAS_LSP_MESSAGE_RATE_BURST=32 jas_lsp_bridge.cpp -o /tmp/jas-lsp-bridge-rate-test $(LDLIBS)
	../../../tests/test_jas_lsp_bridge.sh ./jas-lsp-bridge /tmp/jas-lsp-bridge-timeout-test /tmp/jas-lsp-bridge-rate-test
	../../../tests/test_jas_lsp_capabilities.sh ./jas-lsp-bridge
	../../../tests/test_jas_lsp_fuzz.sh ./jas-lsp-bridge
	rm -f /tmp/jas-lsp-bridge-timeout-test /tmp/jas-lsp-bridge-rate-test

clean:
	rm -f jas-lsp-bridge
