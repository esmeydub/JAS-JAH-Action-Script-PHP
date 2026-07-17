CXX ?= g++
CXXFLAGS ?= -std=c++20 -O2 -Wall -Wextra -Wpedantic -Werror
LDLIBS ?= -lcrypto -pthread

.PHONY: all test clean

all: jas-lsp-bridge

jas-lsp-bridge: jas_lsp_bridge.cpp
	$(CXX) $(CXXFLAGS) $< -o $@ $(LDLIBS)

test: jas-lsp-bridge
	../../../tests/test_jas_lsp_bridge.sh ./jas-lsp-bridge

clean:
	rm -f jas-lsp-bridge
