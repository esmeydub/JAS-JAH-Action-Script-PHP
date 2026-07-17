#include <rapidjson/document.h>
#include <rapidjson/stringbuffer.h>
#include <rapidjson/writer.h>

#include <openssl/core_names.h>
#include <openssl/crypto.h>
#include <openssl/evp.h>
#include <openssl/params.h>
#include <openssl/rand.h>

#include <sys/types.h>
#include <sys/resource.h>
#include <sys/stat.h>
#include <sys/wait.h>
#include <poll.h>
#include <unistd.h>
#include <fcntl.h>
#include <limits.h>
#ifdef __linux__
#include <sys/prctl.h>
#endif

#include <algorithm>
#include <array>
#include <atomic>
#include <cerrno>
#include <chrono>
#include <cstdint>
#include <cstring>
#include <iostream>
#include <limits>
#include <map>
#include <mutex>
#include <optional>
#include <set>
#include <stdexcept>
#include <string>
#include <thread>
#include <variant>
#include <vector>

namespace {

constexpr std::size_t MAX_LSP_BYTES = 8u * 1024u * 1024u;
constexpr std::size_t MAX_DEPTH = 16;
constexpr std::size_t MAX_ITEMS = 4096;
constexpr std::size_t MAX_JSON_ITEMS = 65536;
constexpr std::size_t MAX_PENDING_REQUESTS = 256;
#ifndef JAS_LSP_REQUEST_TIMEOUT_MS
#define JAS_LSP_REQUEST_TIMEOUT_MS 15000
#endif
constexpr auto REQUEST_TIMEOUT = std::chrono::milliseconds(JAS_LSP_REQUEST_TIMEOUT_MS);
static_assert(JAS_LSP_REQUEST_TIMEOUT_MS >= 100 && JAS_LSP_REQUEST_TIMEOUT_MS <= 120000,
    "JAS LSP request timeout must remain bounded");
constexpr std::uint16_t OP_INITIALIZE = 600, OP_INITIALIZED = 601;
constexpr std::uint16_t OP_OPEN = 610, OP_CHANGE = 611, OP_CLOSE = 612;
constexpr std::uint16_t OP_HOVER = 620, OP_DEFINITION = 621, OP_REFERENCES = 622;
constexpr std::uint16_t OP_PREPARE_RENAME = 623, OP_RENAME = 624, OP_DIAGNOSTICS = 630;
constexpr std::uint16_t OP_SHUTDOWN = 640, OP_EXIT = 641, OP_RESPONSE = 690, OP_ERROR = 691;

struct Value;
using List = std::vector<Value>;
using Map = std::map<std::string, Value>;
struct Value {
    using Data = std::variant<std::nullptr_t, bool, std::int32_t, std::string, List, Map>;
    Data data;
    Value() : data(nullptr) {}
    Value(std::nullptr_t) : data(nullptr) {}
    Value(bool value) : data(value) {}
    Value(int value) : data(static_cast<std::int32_t>(value)) {}
    Value(std::string value) : data(std::move(value)) {}
    Value(const char* value) : data(std::string(value)) {}
    Value(List value) : data(std::move(value)) {}
    Value(Map value) : data(std::move(value)) {}
};

class LspRequestError final : public std::runtime_error {
public:
    LspRequestError(int code, const char* message) : std::runtime_error(message), code_(code) {}
    int code() const noexcept { return code_; }
private:
    int code_;
};

bool validUtf8(const std::string& value) {
    const auto* bytes = reinterpret_cast<const unsigned char*>(value.data());
    std::size_t at = 0;
    while (at < value.size()) {
        const auto first = bytes[at++];
        if (first <= 0x7f) continue;
        std::size_t remaining = 0; std::uint32_t point = 0;
        if (first >= 0xc2 && first <= 0xdf) { remaining = 1; point = first & 0x1f; }
        else if (first >= 0xe0 && first <= 0xef) { remaining = 2; point = first & 0x0f; }
        else if (first >= 0xf0 && first <= 0xf4) { remaining = 3; point = first & 0x07; }
        else return false;
        if (at + remaining > value.size()) return false;
        for (std::size_t i = 0; i < remaining; ++i) {
            const auto next = bytes[at++]; if ((next & 0xc0) != 0x80) return false;
            point = (point << 6) | (next & 0x3f);
        }
        if ((remaining == 2 && point < 0x800) || (remaining == 3 && point < 0x10000)
            || (point >= 0xd800 && point <= 0xdfff) || point > 0x10ffff) return false;
    }
    return true;
}

bool validKey(const std::string& key) {
    if (key.empty() || key.size() > 64 || !std::isalpha(static_cast<unsigned char>(key[0]))) return false;
    return std::all_of(key.begin() + 1, key.end(), [](unsigned char c) {
        return std::isalnum(c) || c == '_' || c == '.' || c == ':' || c == '-';
    });
}

void appendU16(std::vector<std::uint8_t>& out, std::uint16_t value) {
    out.push_back(static_cast<std::uint8_t>(value >> 8)); out.push_back(static_cast<std::uint8_t>(value));
}
void appendU32(std::vector<std::uint8_t>& out, std::uint32_t value) {
    out.push_back(static_cast<std::uint8_t>(value >> 24)); out.push_back(static_cast<std::uint8_t>(value >> 16));
    out.push_back(static_cast<std::uint8_t>(value >> 8)); out.push_back(static_cast<std::uint8_t>(value));
}
std::uint16_t readU16(const std::vector<std::uint8_t>& in, std::size_t at) {
    if (at + 2 > in.size()) throw std::runtime_error("binary_truncated");
    return static_cast<std::uint16_t>((in[at] << 8) | in[at + 1]);
}
std::uint32_t readU32(const std::vector<std::uint8_t>& in, std::size_t at) {
    if (at + 4 > in.size()) throw std::runtime_error("binary_truncated");
    return (static_cast<std::uint32_t>(in[at]) << 24) | (static_cast<std::uint32_t>(in[at + 1]) << 16)
        | (static_cast<std::uint32_t>(in[at + 2]) << 8) | in[at + 3];
}

void encodeValue(std::vector<std::uint8_t>& out, const Value& value, std::size_t depth) {
    if (depth > MAX_DEPTH) throw std::runtime_error("payload_depth");
    std::uint8_t type = 0; std::vector<std::uint8_t> body;
    if (std::holds_alternative<std::nullptr_t>(value.data)) type = 0;
    else if (const auto* item = std::get_if<bool>(&value.data)) type = *item ? 2 : 1;
    else if (const auto* item = std::get_if<std::int32_t>(&value.data)) { type = 3; appendU32(body, static_cast<std::uint32_t>(*item)); }
    else if (const auto* item = std::get_if<std::string>(&value.data)) {
        if (item->size() > 4u * 1024u * 1024u || !validUtf8(*item)) throw std::runtime_error("payload_string");
        type = 4; body.insert(body.end(), item->begin(), item->end());
    } else if (const auto* items = std::get_if<List>(&value.data)) {
        if (items->size() > MAX_ITEMS) throw std::runtime_error("payload_items");
        type = 5; appendU16(body, static_cast<std::uint16_t>(items->size()));
        for (const auto& item : *items) encodeValue(body, item, depth + 1);
    } else {
        const auto& mapItems = std::get<Map>(value.data);
        if (mapItems.size() > MAX_ITEMS) throw std::runtime_error("payload_items");
        type = 6; appendU16(body, static_cast<std::uint16_t>(mapItems.size()));
        for (const auto& [key, item] : mapItems) {
            if (!validKey(key)) throw std::runtime_error("payload_key");
            body.push_back(static_cast<std::uint8_t>(key.size())); body.insert(body.end(), key.begin(), key.end());
            encodeValue(body, item, depth + 1);
        }
    }
    out.push_back(type); appendU32(out, static_cast<std::uint32_t>(body.size())); out.insert(out.end(), body.begin(), body.end());
}

Value decodeValue(const std::vector<std::uint8_t>& in, std::size_t& at, std::size_t depth) {
    if (depth > MAX_DEPTH || at + 5 > in.size()) throw std::runtime_error("payload_truncated");
    const auto type = in[at++]; const auto length = readU32(in, at); at += 4;
    const std::size_t end = at + length; if (end < at || end > in.size()) throw std::runtime_error("payload_truncated");
    if (type <= 2) { if (length != 0) throw std::runtime_error("payload_scalar"); return type == 0 ? Value(nullptr) : Value(type == 2); }
    if (type == 3) { if (length != 4) throw std::runtime_error("payload_integer"); auto n = readU32(in, at); at = end; return Value(static_cast<std::int32_t>(n)); }
    if (type == 4) { std::string text(reinterpret_cast<const char*>(in.data() + at), length); at = end;
        if (text.size() > 4u * 1024u * 1024u || !validUtf8(text)) throw std::runtime_error("payload_string");
        return Value(std::move(text)); }
    if ((type != 5 && type != 6) || length < 2) throw std::runtime_error("payload_type");
    const auto count = readU16(in, at); at += 2; if (count > MAX_ITEMS) throw std::runtime_error("payload_items");
    if (type == 5) {
        List list; list.reserve(count); for (std::size_t i = 0; i < count; ++i) list.push_back(decodeValue(in, at, depth + 1));
        if (at != end) throw std::runtime_error("payload_container");
        return Value(std::move(list));
    }
    Map map;
    for (std::size_t i = 0; i < count; ++i) {
        if (at >= end) throw std::runtime_error("payload_key");
        const auto n = in[at++];
        if (n == 0 || at + n > end) throw std::runtime_error("payload_key");
        std::string key(reinterpret_cast<const char*>(in.data() + at), n); at += n;
        if (!validKey(key)) throw std::runtime_error("payload_key");
        if (!map.emplace(std::move(key), decodeValue(in, at, depth + 1)).second) throw std::runtime_error("payload_key");
    }
    if (at != end) throw std::runtime_error("payload_container");
    return Value(std::move(map));
}

std::vector<std::uint8_t> encodePayload(const Map& message) {
    std::vector<std::uint8_t> out{'J','A','S','L',1}; encodeValue(out, Value(message), 0);
    if (out.size() > MAX_LSP_BYTES) throw std::runtime_error("payload_large");
    return out;
}
Map decodePayload(const std::vector<std::uint8_t>& bytes) {
    if (bytes.size() < 10 || !std::equal(bytes.begin(), bytes.begin() + 5, std::array<std::uint8_t,5>{'J','A','S','L',1}.begin())) throw std::runtime_error("payload_header");
    std::size_t at = 5; Value value = decodeValue(bytes, at, 0);
    if (at != bytes.size() || !std::holds_alternative<Map>(value.data)) throw std::runtime_error("payload_root");
    return std::get<Map>(std::move(value.data));
}

std::array<std::uint8_t,32> digest(const std::vector<std::uint8_t>& data) {
    std::array<std::uint8_t,32> out{}; std::size_t size = out.size();
    if (EVP_Q_digest(nullptr, "SHA256", nullptr, data.data(), data.size(), out.data(), &size) != 1 || size != out.size()) throw std::runtime_error("digest_failed");
    return out;
}
std::array<std::uint8_t,32> hmac(const std::array<std::uint8_t,32>& key, const std::vector<std::uint8_t>& data) {
    std::array<std::uint8_t,32> out{}; std::size_t size = out.size(); EVP_MAC* mac = EVP_MAC_fetch(nullptr, "HMAC", nullptr);
    if (!mac) throw std::runtime_error("hmac_failed");
    EVP_MAC_CTX* ctx = EVP_MAC_CTX_new(mac); EVP_MAC_free(mac);
    char digestName[] = "SHA256"; OSSL_PARAM params[] = { OSSL_PARAM_construct_utf8_string(OSSL_MAC_PARAM_DIGEST, digestName, 0), OSSL_PARAM_construct_end() };
    const bool ok = ctx && EVP_MAC_init(ctx, key.data(), key.size(), params) == 1 && EVP_MAC_update(ctx, data.data(), data.size()) == 1
        && EVP_MAC_final(ctx, out.data(), &size, out.size()) == 1 && size == out.size(); EVP_MAC_CTX_free(ctx);
    if (!ok) throw std::runtime_error("hmac_failed");
    return out;
}
std::string hex(const std::uint8_t* data, std::size_t length) {
    static constexpr char chars[] = "0123456789abcdef"; std::string out(length * 2, '0');
    for (std::size_t i = 0; i < length; ++i) { out[i*2] = chars[data[i] >> 4]; out[i*2+1] = chars[data[i] & 15]; } return out;
}

void writeExact(int fd, const std::uint8_t* data, std::size_t length) {
    while (length) { const auto n = ::write(fd, data, length); if (n < 0 && errno == EINTR) continue; if (n <= 0) throw std::runtime_error("write_failed"); data += n; length -= static_cast<std::size_t>(n); }
}
bool descriptorReady(int fd, int timeoutMilliseconds) {
    struct pollfd descriptor{fd, POLLIN, 0};
    while (true) {
        const int result = poll(&descriptor, 1, timeoutMilliseconds);
        if (result < 0 && errno == EINTR) continue;
        if (result < 0) throw std::runtime_error("poll_failed");
        if (result == 0) return false;
        return (descriptor.revents & (POLLIN | POLLHUP | POLLERR | POLLNVAL)) != 0;
    }
}

void readExactTimed(int fd, std::uint8_t* data, std::size_t length) {
    while (length) {
        if (!descriptorReady(fd, JAS_LSP_REQUEST_TIMEOUT_MS)) throw std::runtime_error("backend_read_timeout");
        const auto n = ::read(fd, data, length);
        if (n < 0 && errno == EINTR) continue;
        if (n <= 0) throw std::runtime_error("read_failed");
        data += n; length -= static_cast<std::size_t>(n);
    }
}

struct Packet { std::uint16_t opcode{}; std::string request; std::string session; std::vector<std::uint8_t> payload; };
class JasChannel {
    int input_, output_; std::array<std::uint8_t,32> key_; std::string session_; std::atomic<std::uint64_t> sequence_{0};
public:
    JasChannel(int input, int output, std::array<std::uint8_t,32> key, std::string session) : input_(input), output_(output), key_(key), session_(std::move(session)) {}
    ~JasChannel() { OPENSSL_cleanse(key_.data(), key_.size()); }
    bool ready(int timeoutMilliseconds) const { return descriptorReady(input_, timeoutMilliseconds); }
    void send(std::uint16_t opcode, const Map& payload) {
        const auto encoded = encodePayload(payload); const auto sequence = ++sequence_;
        std::vector<std::uint8_t> seed(session_.begin(), session_.end()); for (int shift = 56; shift >= 0; shift -= 8) seed.push_back(static_cast<std::uint8_t>(sequence >> shift));
        const auto idHash = digest(seed); const std::string request = hex(idHash.data(), idHash.size());
        std::vector<std::uint8_t> body;
        body.reserve(36 + request.size() + session_.size() + encoded.size() + 32);
        body.insert(body.end(), {'J','A','S','B',2,0}); appendU16(body, opcode); appendU16(body, 0);
        appendU32(body, static_cast<std::uint32_t>(std::time(nullptr))); appendU32(body, static_cast<std::uint32_t>(encoded.size()));
        body.push_back(static_cast<std::uint8_t>(request.size())); body.push_back(static_cast<std::uint8_t>(session_.size())); body.insert(body.end(), 16, 0);
        body.insert(body.end(), request.begin(), request.end()); body.insert(body.end(), session_.begin(), session_.end()); body.insert(body.end(), encoded.begin(), encoded.end());
        const auto signature = hmac(key_, body); body.insert(body.end(), signature.begin(), signature.end());
        std::array<std::uint8_t,4> header{static_cast<std::uint8_t>(body.size() >> 24), static_cast<std::uint8_t>(body.size() >> 16), static_cast<std::uint8_t>(body.size() >> 8), static_cast<std::uint8_t>(body.size())};
        writeExact(output_, header.data(), header.size()); writeExact(output_, body.data(), body.size());
    }
    Packet receive() {
        std::array<std::uint8_t,4> header{}; readExactTimed(input_, header.data(), header.size());
        const std::size_t size = (static_cast<std::size_t>(header[0]) << 24) | (static_cast<std::size_t>(header[1]) << 16) | (static_cast<std::size_t>(header[2]) << 8) | header[3];
        if (size < 68 || size > MAX_LSP_BYTES + 1024) throw std::runtime_error("packet_size");
        std::vector<std::uint8_t> bytes(size); readExactTimed(input_, bytes.data(), size);
        if (!std::equal(bytes.begin(), bytes.begin()+5, std::array<std::uint8_t,5>{'J','A','S','B',2}.begin())) throw std::runtime_error("packet_header");
        const auto payloadLength = readU32(bytes, 14); const auto requestLength = bytes[18], sessionLength = bytes[19];
        const std::size_t bodyLength = 36u + requestLength + sessionLength + payloadLength;
        if (bodyLength + 32 != bytes.size()) throw std::runtime_error("packet_length");
        std::vector<std::uint8_t> body(bytes.begin(), bytes.begin() + static_cast<std::ptrdiff_t>(bodyLength)); const auto expected = hmac(key_, body);
        if (CRYPTO_memcmp(expected.data(), bytes.data() + bodyLength, expected.size()) != 0) throw std::runtime_error("packet_signature");
        Packet packet; packet.opcode = readU16(bytes, 6); std::size_t at = 36;
        packet.request.assign(reinterpret_cast<const char*>(bytes.data()+at), requestLength); at += requestLength;
        packet.session.assign(reinterpret_cast<const char*>(bytes.data()+at), sessionLength); at += sessionLength;
        const auto timestamp = readU32(bytes, 10); const auto now = static_cast<std::uint32_t>(std::time(nullptr));
        if (bytes[5] != 0 || readU16(bytes, 8) != 0 || requestLength == 0 || sessionLength == 0
            || (timestamp > now ? timestamp - now : now - timestamp) > 60
            || (packet.opcode != OP_RESPONSE && packet.opcode != OP_ERROR && packet.opcode != OP_DIAGNOSTICS)
            || packet.session.size() != session_.size() || CRYPTO_memcmp(packet.session.data(), session_.data(), session_.size()) != 0) {
            throw std::runtime_error("packet_contract");
        }
        packet.payload.assign(bytes.begin()+static_cast<std::ptrdiff_t>(at), bytes.begin()+static_cast<std::ptrdiff_t>(at+payloadLength));
        return packet;
    }
};

const Map& asMap(const Value& value) { return std::get<Map>(value.data); }
const List& asList(const Value& value) { return std::get<List>(value.data); }
const std::string& asString(const Value& value) { return std::get<std::string>(value.data); }
std::int32_t asInt(const Value& value) { return std::get<std::int32_t>(value.data); }
const Value& field(const Map& map, const char* key) { auto it = map.find(key); if (it == map.end()) throw std::runtime_error("field_missing"); return it->second; }

void toJson(const Value& value, rapidjson::Value& out, rapidjson::Document::AllocatorType& allocator) {
    if (std::holds_alternative<std::nullptr_t>(value.data)) out.SetNull();
    else if (auto item = std::get_if<bool>(&value.data)) out.SetBool(*item);
    else if (auto item = std::get_if<std::int32_t>(&value.data)) out.SetInt(*item);
    else if (auto item = std::get_if<std::string>(&value.data)) out.SetString(item->data(), static_cast<rapidjson::SizeType>(item->size()), allocator);
    else if (auto items = std::get_if<List>(&value.data)) { out.SetArray(); for (const auto& item : *items) { rapidjson::Value child; toJson(item, child, allocator); out.PushBack(child, allocator); } }
    else { out.SetObject(); for (const auto& [key,item] : std::get<Map>(value.data)) { rapidjson::Value name(key.data(), static_cast<rapidjson::SizeType>(key.size()), allocator), child; toJson(item, child, allocator); out.AddMember(name, child, allocator); } }
}

class LspWriter {
    std::mutex mutex_;
public:
    void write(rapidjson::Document& document) {
        rapidjson::StringBuffer buffer; rapidjson::Writer<rapidjson::StringBuffer> writer(buffer); if (!document.Accept(writer)) throw std::runtime_error("json_write");
        std::lock_guard lock(mutex_); std::cout << "Content-Length: " << buffer.GetSize() << "\r\n\r\n"; std::cout.write(buffer.GetString(), static_cast<std::streamsize>(buffer.GetSize())); std::cout.flush();
    }
    void error(const rapidjson::Value* id, int code, const char* message) {
        rapidjson::Document out; out.SetObject(); auto& a=out.GetAllocator(); out.AddMember("jsonrpc", "2.0", a);
        rapidjson::Value copied; if (id) copied.CopyFrom(*id,a); else copied.SetNull(); out.AddMember("id",copied,a);
        rapidjson::Value error(rapidjson::kObjectType); error.AddMember("code",code,a); error.AddMember("message",rapidjson::Value(message,a),a); out.AddMember("error",error,a); write(out);
    }
};

std::optional<std::string> readLspMessage() {
    std::string line; std::optional<std::size_t> length; std::size_t headerBytes=0;
    while (std::getline(std::cin,line)) {
        headerBytes += line.size()+1; if (headerBytes > 8192) throw std::runtime_error("headers_large");
        if (!line.empty() && line.back()=='\r') line.pop_back();
        if (line.empty()) break;
        const auto colon=line.find(':'); if (colon==std::string::npos) throw std::runtime_error("header_invalid");
        std::string name=line.substr(0,colon); std::transform(name.begin(),name.end(),name.begin(),[](unsigned char c){return static_cast<char>(std::tolower(c));});
        if (name=="content-length") { if (length) throw std::runtime_error("length_duplicate"); std::string value=line.substr(colon+1); value.erase(0,value.find_first_not_of(" \t"));
            if (value.empty() || value.find_first_not_of("0123456789")!=std::string::npos) throw std::runtime_error("length_invalid");
            const auto parsed=std::stoull(value); if (parsed==0 || parsed>MAX_LSP_BYTES) throw std::runtime_error("length_invalid"); length=static_cast<std::size_t>(parsed); }
    }
    if (!length) { if (std::cin.eof()) return std::nullopt; throw std::runtime_error("length_missing"); }
    std::string body(*length,'\0'); std::cin.read(body.data(),static_cast<std::streamsize>(*length)); if (static_cast<std::size_t>(std::cin.gcount())!=*length) throw std::runtime_error("body_truncated"); return body;
}

void validateJson(const rapidjson::Value& value, std::size_t depth, std::size_t& items) {
    if (depth > MAX_DEPTH || ++items > MAX_JSON_ITEMS) throw std::runtime_error("json_limits");
    if (value.IsString() && value.GetStringLength() > 4u * 1024u * 1024u) throw std::runtime_error("json_string");
    if (value.IsArray()) {
        if (value.Size() > MAX_ITEMS) throw std::runtime_error("json_items");
        for (const auto& child : value.GetArray()) validateJson(child, depth + 1, items);
    } else if (value.IsObject()) {
        if (value.MemberCount() > MAX_ITEMS) throw std::runtime_error("json_items");
        std::set<std::string> names;
        for (auto it = value.MemberBegin(); it != value.MemberEnd(); ++it) {
            std::string name(it->name.GetString(), it->name.GetStringLength());
            if (!names.insert(name).second) throw std::runtime_error("json_duplicate_key");
            validateJson(it->value, depth + 1, items);
        }
    }
}

Value idValue(const rapidjson::Value& id) {
    if (id.IsInt() && id.GetInt() >= 0) return Value(id.GetInt());
    if (id.IsString() && id.GetStringLength() && id.GetStringLength() <= 128) return Value(std::string(id.GetString(),id.GetStringLength()));
    throw std::runtime_error("id_invalid");
}
std::uint16_t opcodeFor(const std::string& method) {
    static const std::map<std::string,std::uint16_t> methods{{"initialize",OP_INITIALIZE},{"initialized",OP_INITIALIZED},{"textDocument/didOpen",OP_OPEN},{"textDocument/didChange",OP_CHANGE},{"textDocument/didClose",OP_CLOSE},{"textDocument/hover",OP_HOVER},{"textDocument/definition",OP_DEFINITION},{"textDocument/references",OP_REFERENCES},{"textDocument/prepareRename",OP_PREPARE_RENAME},{"textDocument/rename",OP_RENAME},{"shutdown",OP_SHUTDOWN},{"exit",OP_EXIT}};
    auto it=methods.find(method); if(it==methods.end()) throw std::runtime_error("method_unsupported"); return it->second;
}
std::string kindFor(const std::string& method) { return method=="initialized" || method=="textDocument/didOpen" || method=="textDocument/didChange" || method=="textDocument/didClose" || method=="exit" ? "notification" : "request"; }

class Translator {
    std::mutex mutex_; std::map<std::string,std::int32_t> versions_;
    std::map<std::string,std::chrono::steady_clock::time_point> pending_;
    std::set<std::string> cancelled_;
    std::string encoding_="utf-16"; std::string workspace_;
    bool documentChanges_=false,renameFile_=false,changeAnnotations_=false;
    static const rapidjson::Value& required(const rapidjson::Value& object,const char* name){if(!object.IsObject()||!object.HasMember(name))throw std::runtime_error("params_invalid");return object[name];}
    static std::string string(const rapidjson::Value& value){if(!value.IsString())throw std::runtime_error("params_invalid");return {value.GetString(),value.GetStringLength()};}
    std::int32_t version(const std::string& uri){std::lock_guard lock(mutex_);auto it=versions_.find(uri);return it==versions_.end()?0:it->second;}
    static std::string idKey(const Value& value) {if(const auto* id=std::get_if<std::int32_t>(&value.data))return "i:"+std::to_string(*id);if(const auto* id=std::get_if<std::string>(&value.data))return "s:"+*id;throw std::runtime_error("id_invalid");}
    void registerRequest(const Value& id){std::lock_guard lock(mutex_);if(pending_.size()>=MAX_PENDING_REQUESTS||!pending_.emplace(idKey(id),std::chrono::steady_clock::now()+REQUEST_TIMEOUT).second)throw LspRequestError(-32000,"Server request limit reached");}
    void cancelRequest(const Value& id){std::lock_guard lock(mutex_);const auto key=idKey(id);if(pending_.contains(key))cancelled_.insert(key);}
    bool completeRequest(const Value& id){std::lock_guard lock(mutex_);const auto key=idKey(id);if(pending_.erase(key)!=1)throw std::runtime_error("response_unmatched");return cancelled_.erase(key)==1;}
    std::array<bool,3> renameCapabilities(){std::lock_guard lock(mutex_);return {documentChanges_,renameFile_,changeAnnotations_};}
public:
    bool requestExpired(){std::lock_guard lock(mutex_);const auto now=std::chrono::steady_clock::now();return std::any_of(pending_.begin(),pending_.end(),[now](const auto& item){return item.second<=now;});}
    std::pair<std::uint16_t,Map> inbound(const rapidjson::Document& doc) {
        if(!doc.IsObject()||!doc.HasMember("jsonrpc")||!doc["jsonrpc"].IsString()||std::string_view(doc["jsonrpc"].GetString(),doc["jsonrpc"].GetStringLength())!="2.0"||!doc.HasMember("method")||!doc["method"].IsString())throw std::runtime_error("request_invalid");
        const std::string method(doc["method"].GetString(),doc["method"].GetStringLength());
        if(method=="$/cancelRequest") {if(doc.HasMember("id")||!doc.HasMember("params")||!doc["params"].IsObject()||!doc["params"].HasMember("id"))throw std::runtime_error("cancel_invalid");cancelRequest(idValue(doc["params"]["id"]));return {0,{}};}
        const std::string kind=kindFor(method);
        if((kind=="request")!=doc.HasMember("id"))throw std::runtime_error("id_direction");
        const rapidjson::Value empty(rapidjson::kObjectType); const auto& params=doc.HasMember("params")?doc["params"]:empty;
        Map body;
        if(method=="initialize") {
            if(!params.IsObject())throw std::runtime_error("params_invalid");
            std::string workspace;
            if(params.HasMember("rootUri")&&params["rootUri"].IsString())workspace=string(params["rootUri"]);
            else if(params.HasMember("workspaceFolders")&&params["workspaceFolders"].IsArray()&&!params["workspaceFolders"].Empty())workspace=string(required(params["workspaceFolders"][0],"uri"));
            if(workspace.empty())throw std::runtime_error("workspace_missing");
            workspace_=workspace; List encodings;
            if(params.HasMember("capabilities")&&params["capabilities"].IsObject()) { const auto& caps=params["capabilities"]; if(caps.HasMember("general")&&caps["general"].IsObject()&&caps["general"].HasMember("positionEncodings")&&caps["general"]["positionEncodings"].IsArray()) for(const auto& item:caps["general"]["positionEncodings"].GetArray()) if(item.IsString()){std::string e=string(item);if(e=="utf-8"||e=="utf-16"||e=="utf-32")encodings.emplace_back(e);}
                if(caps.HasMember("workspace")&&caps["workspace"].IsObject()&&caps["workspace"].HasMember("workspaceEdit")&&caps["workspace"]["workspaceEdit"].IsObject()) {const auto& edit=caps["workspace"]["workspaceEdit"];const bool documents=edit.HasMember("documentChanges")&&edit["documentChanges"].IsBool()&&edit["documentChanges"].GetBool();bool rename=false;if(documents&&edit.HasMember("resourceOperations")&&edit["resourceOperations"].IsArray())for(const auto& operation:edit["resourceOperations"].GetArray())if(operation.IsString()&&std::string_view(operation.GetString(),operation.GetStringLength())=="rename")rename=true;const bool annotations=documents&&edit.HasMember("changeAnnotationSupport")&&edit["changeAnnotationSupport"].IsObject();std::lock_guard lock(mutex_);documentChanges_=documents;renameFile_=rename;changeAnnotations_=annotations;}
            }
            if(encodings.empty())encodings.emplace_back("utf-16");
            encoding_=asString(encodings.front()); std::int32_t pid=0;
            if(params.HasMember("processId")&&!params["processId"].IsNull()){if(!params["processId"].IsInt()||params["processId"].GetInt()<0)throw std::runtime_error("pid_invalid");pid=params["processId"].GetInt();}
            body={{"workspace_uri",workspace},{"process_id",pid},{"position_encodings",encodings}};
        } else if(method=="textDocument/didOpen") {
            const auto& text=required(params,"textDocument"); auto uri=string(required(text,"uri")); const auto& versionValue=required(text,"version");if(!versionValue.IsInt()||versionValue.GetInt()<0)throw std::runtime_error("version_invalid");auto v=versionValue.GetInt(); {std::lock_guard lock(mutex_);versions_[uri]=v;}
            body={{"uri",uri},{"version",v},{"language_id",string(required(text,"languageId"))},{"content",string(required(text,"text"))}};
        } else if(method=="textDocument/didChange") {
            const auto& text=required(params,"textDocument"); auto uri=string(required(text,"uri")); if(!required(text,"version").IsInt()||required(text,"version").GetInt()<0)throw std::runtime_error("version_invalid");auto v=required(text,"version").GetInt();
            const auto& changes=required(params,"contentChanges");if(!changes.IsArray()||changes.Size()!=1||!changes[0].IsObject()||!changes[0].HasMember("text")||changes[0].HasMember("range"))throw std::runtime_error("full_sync_required");{std::lock_guard lock(mutex_);versions_[uri]=v;}
            body={{"uri",uri},{"version",v},{"changes",List{Map{{"text",string(changes[0]["text"])}}}}};
        } else if(method=="textDocument/didClose") {auto uri=string(required(required(params,"textDocument"),"uri"));{std::lock_guard lock(mutex_);versions_.erase(uri);}body={{"uri",uri}};}
        else if(method=="textDocument/hover"||method=="textDocument/definition"||method=="textDocument/references"||method=="textDocument/prepareRename"||method=="textDocument/rename") {
            auto uri=string(required(required(params,"textDocument"),"uri"));const auto& pos=required(params,"position");if(!required(pos,"line").IsInt()||required(pos,"line").GetInt()<0||!required(pos,"character").IsInt()||required(pos,"character").GetInt()<0)throw std::runtime_error("position_invalid");
            body={{"uri",uri},{"version",version(uri)},{"line",required(pos,"line").GetInt()},{"character",required(pos,"character").GetInt()},{"position_encoding",encoding_}};if(method=="textDocument/rename")body["new_name"]=string(required(params,"newName"));
        }
        Value external=kind=="request"?idValue(doc["id"]):Value(nullptr);if(kind=="request")registerRequest(external);
        Map message{{"schema","JAS_LANGUAGE_1"},{"kind",kind},{"method",method},{"external_id",external},{"body",body}}; return {opcodeFor(method),std::move(message)};
    }
    void outbound(const Packet& packet,LspWriter& writer) {
        const auto envelope=decodePayload(packet.payload);const auto kind=asString(field(envelope,"kind"));const auto method=asString(field(envelope,"method"));
        const Map emptyBody; const auto& bodyValue=field(envelope,"body");
        const auto& body=std::holds_alternative<Map>(bodyValue.data)?asMap(bodyValue):emptyBody;
        if(!std::holds_alternative<Map>(bodyValue.data)&&(!std::holds_alternative<List>(bodyValue.data)||!asList(bodyValue).empty()))throw std::runtime_error("response_body_invalid");
        rapidjson::Document out;out.SetObject();auto& a=out.GetAllocator();out.AddMember("jsonrpc","2.0",a);
        if(kind=="notification") {out.AddMember("method",rapidjson::Value(method.data(),static_cast<rapidjson::SizeType>(method.size()),a),a);rapidjson::Value params;
            if(method=="textDocument/publishDiagnostics") {
                params.SetObject(); const auto& uri=asString(field(body,"uri"));
                params.AddMember("uri",rapidjson::Value(uri.data(),static_cast<rapidjson::SizeType>(uri.size()),a),a);
                params.AddMember("version",asInt(field(body,"version")),a); rapidjson::Value diagnostics(rapidjson::kArrayType);
                for(const auto& item:asList(field(body,"diagnostics"))) {const auto& source=asMap(item);rapidjson::Value diagnostic(rapidjson::kObjectType),range(rapidjson::kObjectType),start(rapidjson::kObjectType),end(rapidjson::kObjectType);
                    start.AddMember("line",asInt(field(source,"start_line")),a);start.AddMember("character",asInt(field(source,"start_character")),a);
                    end.AddMember("line",asInt(field(source,"end_line")),a);end.AddMember("character",asInt(field(source,"end_character")),a);range.AddMember("start",start,a);range.AddMember("end",end,a);diagnostic.AddMember("range",range,a);
                    diagnostic.AddMember("severity",asInt(field(source,"severity")),a);const auto& code=asString(field(source,"code"));const auto& message=asString(field(source,"message"));diagnostic.AddMember("code",rapidjson::Value(code.data(),static_cast<rapidjson::SizeType>(code.size()),a),a);diagnostic.AddMember("source","jas",a);diagnostic.AddMember("message",rapidjson::Value(message.data(),static_cast<rapidjson::SizeType>(message.size()),a),a);diagnostics.PushBack(diagnostic,a);}
                params.AddMember("diagnostics",diagnostics,a);
            } else params.SetObject();out.AddMember("params",params,a);writer.write(out);return;}
        const bool cancelled=completeRequest(field(envelope,"external_id"));
        rapidjson::Value id;toJson(field(envelope,"external_id"),id,a);out.AddMember("id",id,a);
        if(cancelled){rapidjson::Value error(rapidjson::kObjectType);error.AddMember("code",-32800,a);error.AddMember("message","Request cancelled",a);out.AddMember("error",error,a);writer.write(out);return;}
        if(kind=="error"||packet.opcode==OP_ERROR){rapidjson::Value error(rapidjson::kObjectType);error.AddMember("code",asInt(field(body,"code")),a);const auto& msg=asString(field(body,"message"));error.AddMember("message",rapidjson::Value(msg.data(),static_cast<rapidjson::SizeType>(msg.size()),a),a);out.AddMember("error",error,a);writer.write(out);return;}
        rapidjson::Value result;
        if(method=="initialize"){result.SetObject();rapidjson::Value caps(rapidjson::kObjectType);const auto& e=asString(field(body,"position_encoding"));caps.AddMember("positionEncoding",rapidjson::Value(e.data(),static_cast<rapidjson::SizeType>(e.size()),a),a);rapidjson::Value sync(rapidjson::kObjectType);sync.AddMember("openClose",true,a);sync.AddMember("change",1,a);caps.AddMember("textDocumentSync",sync,a);caps.AddMember("hoverProvider",true,a);caps.AddMember("definitionProvider",true,a);caps.AddMember("referencesProvider",true,a);rapidjson::Value rename(rapidjson::kObjectType);rename.AddMember("prepareProvider",true,a);caps.AddMember("renameProvider",rename,a);result.AddMember("capabilities",caps,a);rapidjson::Value info(rapidjson::kObjectType);info.AddMember("name","JAS Language Server",a);info.AddMember("version","0.1.0",a);result.AddMember("serverInfo",info,a);}
        else if(method=="shutdown")result.SetNull();
        else if(method=="textDocument/hover"){const auto& hover=field(body,"hover");if(std::holds_alternative<std::nullptr_t>(hover.data))result.SetNull();else{const auto& h=asMap(hover);result.SetObject();rapidjson::Value contents(rapidjson::kObjectType);contents.AddMember("kind","plaintext",a);const auto& detail=asString(field(h,"detail"));contents.AddMember("value",rapidjson::Value(detail.data(),static_cast<rapidjson::SizeType>(detail.size()),a),a);result.AddMember("contents",contents,a);rapidjson::Value range;toJson(field(asMap(field(h,"location")),"range"),range,a);result.AddMember("range",range,a);}}
        else if(method=="textDocument/definition"){const auto& location=field(body,"location");toJson(location,result,a);}
        else if(method=="textDocument/references"){toJson(field(body,"locations"),result,a);}
        else if(method=="textDocument/prepareRename"){if(std::holds_alternative<std::nullptr_t>(field(body,"range").data))result.SetNull();else{result.SetObject();rapidjson::Value range;toJson(field(body,"range"),range,a);result.AddMember("range",range,a);const auto& p=asString(field(body,"placeholder"));result.AddMember("placeholder",rapidjson::Value(p.data(),static_cast<rapidjson::SizeType>(p.size()),a),a);}}
        else if(method=="textDocument/rename"){
            result.SetObject();struct EditGroup{Value version;List edits;};std::map<std::string,EditGroup> grouped;
            for(const auto& item:asList(field(body,"changes"))){const auto& edit=asMap(item);const auto& uri=asString(field(edit,"uri"));const auto& version=field(edit,"version");if(!std::holds_alternative<std::nullptr_t>(version.data)&&!std::holds_alternative<std::int32_t>(version.data))throw std::runtime_error("workspace_edit_version_invalid");auto found=grouped.find(uri);if(found==grouped.end())found=grouped.emplace(uri,EditGroup{version,{}}).first;else {const bool bothNull=std::holds_alternative<std::nullptr_t>(version.data)&&std::holds_alternative<std::nullptr_t>(found->second.version.data);const auto* incoming=std::get_if<std::int32_t>(&version.data);const auto* existing=std::get_if<std::int32_t>(&found->second.version.data);if(!bothNull&&(!incoming||!existing||*incoming!=*existing))throw std::runtime_error("workspace_edit_version_mismatch");}found->second.edits.push_back(Map{{"range",field(edit,"range")},{"newText",field(edit,"new_text")}});}
            const auto capabilities=renameCapabilities();
            if(!capabilities[0]){rapidjson::Value changes(rapidjson::kObjectType);for(const auto& [uri,group]:grouped){rapidjson::Value name(uri.data(),static_cast<rapidjson::SizeType>(uri.size()),a),array;toJson(Value(group.edits),array,a);changes.AddMember(name,array,a);}result.AddMember("changes",changes,a);}
            else {rapidjson::Value documentChanges(rapidjson::kArrayType);constexpr const char* annotation="jas.rename";
                for(const auto& [uri,group]:grouped){rapidjson::Value documentEdit(rapidjson::kObjectType),identifier(rapidjson::kObjectType),edits(rapidjson::kArrayType);identifier.AddMember("uri",rapidjson::Value(uri.data(),static_cast<rapidjson::SizeType>(uri.size()),a),a);rapidjson::Value version;toJson(group.version,version,a);identifier.AddMember("version",version,a);documentEdit.AddMember("textDocument",identifier,a);
                    for(const auto& item:group.edits){const auto& edit=asMap(item);rapidjson::Value converted(rapidjson::kObjectType),range;toJson(field(edit,"range"),range,a);converted.AddMember("range",range,a);const auto& text=asString(field(edit,"newText"));converted.AddMember("newText",rapidjson::Value(text.data(),static_cast<rapidjson::SizeType>(text.size()),a),a);if(capabilities[2])converted.AddMember("annotationId",rapidjson::Value(annotation,a),a);edits.PushBack(converted,a);}documentEdit.AddMember("edits",edits,a);documentChanges.PushBack(documentEdit,a);}
                if(capabilities[1])for(const auto& item:asList(field(body,"file_renames"))){const auto& rename=asMap(item);rapidjson::Value operation(rapidjson::kObjectType);operation.AddMember("kind","rename",a);const auto& oldUri=asString(field(rename,"old_uri"));const auto& newUri=asString(field(rename,"new_uri"));operation.AddMember("oldUri",rapidjson::Value(oldUri.data(),static_cast<rapidjson::SizeType>(oldUri.size()),a),a);operation.AddMember("newUri",rapidjson::Value(newUri.data(),static_cast<rapidjson::SizeType>(newUri.size()),a),a);if(capabilities[2])operation.AddMember("annotationId",rapidjson::Value(annotation,a),a);documentChanges.PushBack(operation,a);}
                result.AddMember("documentChanges",documentChanges,a);if(capabilities[2]){rapidjson::Value annotations(rapidjson::kObjectType),description(rapidjson::kObjectType);description.AddMember("label","Rename JAS symbol",a);description.AddMember("needsConfirmation",true,a);description.AddMember("description","Review governed JAS workspace changes",a);annotations.AddMember(rapidjson::Value(annotation,a),description,a);result.AddMember("changeAnnotations",annotations,a);}
            }
        }
        else result.SetNull();
        out.AddMember("result",result,a);writer.write(out);
    }
};

void interruptHandler(int) {}

std::string canonicalPath(const char* raw, bool directory, bool executable) {
    char resolved[PATH_MAX];
    if (!raw || !*raw || !realpath(raw, resolved)) throw std::runtime_error("startup_path_invalid");
    struct stat info{}; if (stat(resolved, &info) != 0) throw std::runtime_error("startup_path_invalid");
    if ((directory && !S_ISDIR(info.st_mode)) || (!directory && !S_ISREG(info.st_mode))
        || access(resolved, executable ? X_OK : R_OK) != 0) throw std::runtime_error("startup_path_invalid");
    return resolved;
}

void restrictChild(int keyDescriptor) {
    umask(077);
#ifdef __linux__
    if (prctl(PR_SET_DUMPABLE, 0) != 0 || prctl(PR_SET_NO_NEW_PRIVS, 1, 0, 0, 0) != 0) _exit(126);
#endif
    struct rlimit coreLimit{0, 0}; if (setrlimit(RLIMIT_CORE, &coreLimit) != 0) _exit(126);
    long maximum = sysconf(_SC_OPEN_MAX); if (maximum < 0 || maximum > 65536) maximum = 65536;
    for (int descriptor = 3; descriptor < maximum; ++descriptor) if (descriptor != keyDescriptor) close(descriptor);
    struct rlimit fileLimit{64, 64}; if (setrlimit(RLIMIT_NOFILE, &fileLimit) != 0) _exit(126);
}

struct Child { pid_t pid{}; int input{-1}; int output{-1}; };
Child spawnPhp(const std::string& php,const std::string& jas,const std::string& workspace,const std::array<std::uint8_t,32>& key) {
    int toChild[2],fromChild[2],keyPipe[2];
    if(pipe(toChild)||pipe(fromChild)||pipe(keyPipe))throw std::runtime_error("pipe_failed");
    const int nullDescriptor = open("/dev/null", O_WRONLY | O_CLOEXEC); if (nullDescriptor < 0) throw std::runtime_error("stderr_guard_failed");
    pid_t pid=fork();if(pid<0)throw std::runtime_error("fork_failed");
    if(pid==0) {
        if (dup2(toChild[0],STDIN_FILENO) < 0 || dup2(fromChild[1],STDOUT_FILENO) < 0
            || dup2(nullDescriptor,STDERR_FILENO) < 0 || chdir(workspace.c_str()) != 0) _exit(126);
        close(toChild[1]);close(fromChild[0]);close(keyPipe[1]);close(toChild[0]);close(fromChild[1]);close(nullDescriptor);
        if (clearenv() != 0) _exit(126);
        const std::string fd=std::to_string(keyPipe[0]);
        if (setenv("JAS_LANGUAGE_KEY_FD",fd.c_str(),1) != 0 || setenv("LANG","C.UTF-8",1) != 0) _exit(126);
        restrictChild(keyPipe[0]);
        execl(php.c_str(),php.c_str(),jas.c_str(),"language:serve","--stdio",workspace.c_str(),static_cast<char*>(nullptr));_exit(127);
    }
    close(nullDescriptor);close(toChild[0]);close(fromChild[1]);close(keyPipe[0]);
    try { writeExact(keyPipe[1],key.data(),key.size()); } catch (...) { close(keyPipe[1]); close(toChild[1]); close(fromChild[0]); kill(pid,SIGKILL); waitpid(pid,nullptr,0); throw; }
    close(keyPipe[1]);return{pid,fromChild[0],toChild[1]};
}

} // namespace

int main(int argc,char** argv){
    if(argc!=4){std::cerr<<"usage: jas-lsp-bridge <php-binary> <jas-cli> <workspace>\n";return 2;}
    signal(SIGPIPE, SIG_IGN);struct sigaction interruptAction{};interruptAction.sa_handler=interruptHandler;sigemptyset(&interruptAction.sa_mask);interruptAction.sa_flags=0;if(sigaction(SIGUSR1,&interruptAction,nullptr)!=0){std::cerr<<"JAS LSP bridge could not install safeguards\n";return 2;}
    std::string php,jas,workspace;try{php=canonicalPath(argv[1],false,true);jas=canonicalPath(argv[2],false,false);workspace=canonicalPath(argv[3],true,false);}catch(...){std::cerr<<"JAS LSP bridge rejected startup paths\n";return 2;}
    std::array<std::uint8_t,32> key{},sessionBytes{};if(RAND_bytes(key.data(),key.size())!=1||RAND_bytes(sessionBytes.data(),16)!=1){std::cerr<<"JAS LSP bridge could not initialize\n";return 2;}
    Child child{};std::atomic<bool> running{true};std::atomic<bool> timedOut{false},backendFailed{false},gracefulExit{false};try{child=spawnPhp(php,jas,workspace,key);JasChannel channel(child.input,child.output,key,hex(sessionBytes.data(),16));OPENSSL_cleanse(key.data(),key.size());OPENSSL_cleanse(sessionBytes.data(),sessionBytes.size());Translator translator;LspWriter writer;bool framingFailure=false;const pthread_t mainThread=pthread_self();
        std::thread reader([&]{try{while(running){if(channel.ready(25)){translator.outbound(channel.receive(),writer);continue;}if(translator.requestExpired()){timedOut=true;running=false;kill(child.pid,SIGKILL);pthread_kill(mainThread,SIGUSR1);break;}}}catch(...){if(!gracefulExit){backendFailed=true;kill(child.pid,SIGKILL);}running=false;pthread_kill(mainThread,SIGUSR1);}});
        while(running){std::optional<std::string> body;try{body=readLspMessage();}catch(...){if(!timedOut&&!backendFailed)framingFailure=true;break;}if(!body)break;rapidjson::Document doc;doc.Parse<rapidjson::kParseValidateEncodingFlag|rapidjson::kParseStopWhenDoneFlag|rapidjson::kParseIterativeFlag>(body->data(),body->size());if(doc.HasParseError()){try{writer.error(nullptr,-32700,"Parse error");}catch(...){break;}continue;}try{std::size_t jsonItems=0;validateJson(doc,0,jsonItems);auto [opcode,message]=translator.inbound(doc);if(opcode==0)continue;const bool exiting=opcode==OP_EXIT;if(exiting)gracefulExit=true;try{channel.send(opcode,message);}catch(...){if(exiting)gracefulExit=false;throw;}if(exiting)break;}catch(const LspRequestError& error){const rapidjson::Value* id=doc.IsObject()&&doc.HasMember("id")?&doc["id"]:nullptr;if(id){try{writer.error(id,error.code(),error.what());}catch(...){break;}}}catch(const std::exception&){const rapidjson::Value* id=doc.IsObject()&&doc.HasMember("id")?&doc["id"]:nullptr;if(id){try{writer.error(id,-32602,"Invalid or unsupported language request");}catch(...){break;}}}}
        close(child.output);if(reader.joinable())reader.join();running=false;close(child.input);int status=0;waitpid(child.pid,&status,0);if(timedOut){std::cerr<<"JAS LSP bridge terminated an unresponsive backend\n";return 1;}if(framingFailure){std::cerr<<"JAS LSP bridge rejected malformed framing\n";return 1;}if(backendFailed){std::cerr<<"JAS LSP bridge terminated after backend failure\n";return 1;}return WIFEXITED(status)?WEXITSTATUS(status):1;
    }catch(const std::exception&){running=false;OPENSSL_cleanse(key.data(),key.size());OPENSSL_cleanse(sessionBytes.data(),sessionBytes.size());if(child.output>=0)close(child.output);if(child.input>=0)close(child.input);if(child.pid>0){kill(child.pid,SIGTERM);waitpid(child.pid,nullptr,0);}std::cerr<<"JAS LSP bridge stopped safely\n";return 1;}
}
