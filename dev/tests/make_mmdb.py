"""Builds small MaxMind DB (.mmdb) files for testing the plugin's PHP reader.

Independent writer following the MaxMind DB File Format Specification 2.0.
Usage: python make_mmdb.py <out_dir>
"""
import ipaddress
import struct
import sys
import os


class Node:
    __slots__ = ("l", "r")

    def __init__(self):
        self.l = None
        self.r = None


def insert(root, network, key):
    net = ipaddress.ip_network(network)
    if net.version == 4:
        bits = int(net.network_address)  # IPv4 lives at ::a.b.c.d/(96+n)
        plen = 96 + net.prefixlen
    else:
        bits = int(net.network_address)
        plen = net.prefixlen
    node = root
    for i in range(plen):
        bit = (bits >> (127 - i)) & 1
        if i == plen - 1:
            if bit:
                node.r = ("data", key)
            else:
                node.l = ("data", key)
            return
        child = node.r if bit else node.l
        if not isinstance(child, Node):
            child = Node()
            if bit:
                node.r = child
            else:
                node.l = child
        node = child


class Encoder:
    def __init__(self):
        self.buf = bytearray()
        self.strings = {}

    def ctrl(self, type_, size):
        out = bytearray()
        if size < 29:
            size_bits, extra = size, b""
        elif size < 285:
            size_bits, extra = 29, bytes([size - 29])
        elif size < 65821:
            size_bits, extra = 30, struct.pack(">H", size - 285)
        else:
            size_bits, extra = 31, struct.pack(">I", size - 65821)[1:]
        if type_ <= 7:
            out.append((type_ << 5) | size_bits)
        else:
            out.append(size_bits)  # type 0 = extended
            out.append(type_ - 7)
        out += extra
        return bytes(out)

    def pointer(self, p):
        if p < 2048:
            return bytes([0x20 | (p >> 8), p & 0xFF])
        if p < 2048 + 524288:
            q = p - 2048
            return bytes([0x20 | 0x08 | (q >> 16), (q >> 8) & 0xFF, q & 0xFF])
        if p < 526336 + 134217728:
            q = p - 526336
            return bytes([0x20 | 0x10 | (q >> 24), (q >> 16) & 0xFF, (q >> 8) & 0xFF, q & 0xFF])
        return bytes([0x20 | 0x18]) + struct.pack(">I", p)

    def uint(self, type_, value):
        raw = value.to_bytes(max(1, (value.bit_length() + 7) // 8), "big") if value else b""
        return self.ctrl(type_, len(raw)) + raw

    def encode(self, value):
        if isinstance(value, bool):
            return self.ctrl(14, 1 if value else 0)
        if isinstance(value, str):
            raw = value.encode("utf-8")
            if value in self.strings and len(raw) > 3:
                return self.pointer(self.strings[value])
            return self.ctrl(2, len(raw)) + raw
        if isinstance(value, float):
            return self.ctrl(3, 8) + struct.pack(">d", value)
        if isinstance(value, tuple) and value[0] == "int32":
            raw = struct.pack(">i", value[1])
            return self.ctrl(8, 4) + raw
        if isinstance(value, tuple) and value[0] == "u64":
            return self.uint(9, value[1])
        if isinstance(value, tuple) and value[0] == "u16":
            return self.uint(5, value[1])
        if isinstance(value, int):
            return self.uint(6, value)
        if isinstance(value, list):
            out = self.ctrl(11, len(value))
            for item in value:
                out += self.encode(item)
            return out
        if isinstance(value, dict):
            out = self.ctrl(7, len(value))
            for k, v in value.items():
                out += self.encode(k)
                out += self.encode(v)
            return out
        raise TypeError(type(value))

    def add(self, value):
        """Appends a top-level value, remembering string offsets for later pointers."""
        offset = len(self.buf)
        encoded = self.encode(value)
        self.buf += encoded
        self._index_strings(value, offset)
        return offset

    def _index_strings(self, value, base):
        # Register stand-alone strings so later records point to them.
        if isinstance(value, dict):
            for k, v in value.items():
                if isinstance(v, str) and v not in self.strings and len(v.encode()) > 3:
                    self.strings[v] = self.add_raw_string(v)
                elif isinstance(v, (dict, list)):
                    self._index_strings(v, base)
        elif isinstance(value, list):
            for v in value:
                self._index_strings(v, base)

    def add_raw_string(self, s):
        offset = len(self.buf)
        raw = s.encode("utf-8")
        self.buf += self.ctrl(2, len(raw)) + raw
        return offset


def build(path, record_size, records, pad=False):
    root = Node()
    enc = Encoder()
    offsets = {}
    if pad:
        # Two 9 MB blobs push record values above 2^24 so the high nibble of 28-bit records is exercised.
        for _ in range(2):
            blob = b"\x00" * 9000000
            enc.buf += enc.ctrl(4, len(blob)) + blob
    for key, value in records["data"].items():
        offsets[key] = enc.add(value)
    for network, key in records["networks"]:
        insert(root, network, key)

    # Number nodes breadth-first.
    order, queue = [], [root]
    while queue:
        n = queue.pop(0)
        order.append(n)
        for c in (n.l, n.r):
            if isinstance(c, Node):
                queue.append(c)
    index = {id(n): i for i, n in enumerate(order)}
    node_count = len(order)

    def record(child):
        if child is None:
            return node_count
        if isinstance(child, Node):
            return index[id(child)]
        return node_count + 16 + offsets[child[1]]

    tree = bytearray()
    for n in order:
        left, right = record(n.l), record(n.r)
        if record_size == 24:
            tree += left.to_bytes(3, "big") + right.to_bytes(3, "big")
        elif record_size == 28:
            tree += (left & 0xFFFFFF).to_bytes(3, "big")
            tree.append((((left >> 24) & 0x0F) << 4) | ((right >> 24) & 0x0F))
            tree += (right & 0xFFFFFF).to_bytes(3, "big")
        else:
            tree += left.to_bytes(4, "big") + right.to_bytes(4, "big")

    meta = Encoder()
    meta_bytes = meta.encode({
        "binary_format_major_version": ("u16", 2),
        "binary_format_minor_version": ("u16", 0),
        "build_epoch": ("u64", 1791800000),
        "database_type": "Test-City",
        "description": {"en": "Pulse Analytics test database"},
        "ip_version": ("u16", 6),
        "languages": ["en"],
        "node_count": node_count,
        "record_size": ("u16", record_size),
    })
    with open(path, "wb") as fh:
        fh.write(tree)
        fh.write(b"\x00" * 16)
        fh.write(enc.buf)
        fh.write(b"\xab\xcd\xefMaxMind.com")
        fh.write(meta_bytes)
    return node_count


RECORDS = {
    "data": {
        "au": {
            "city": {"geoname_id": 2174003, "names": {"en": "Brisbane", "de": "Brisbane"}},
            "continent": {"code": "OC", "names": {"en": "Oceania"}},
            "country": {"iso_code": "AU", "names": {"en": "Australia"}},
            "location": {"latitude": -27.4679, "longitude": 153.0281, "accuracy_radius": ("u16", 500)},
            "subdivisions": [{"iso_code": "QLD", "names": {"en": "Queensland"}}],
            "is_anycast": False,
        },
        "us": {
            "city": {"names": {"en": "Mountain View"}},
            "country": {"iso_code": "US", "names": {"en": "United States"}},
            "location": {"latitude": 37.386, "longitude": -122.0838},
            "subdivisions": [{"names": {"en": "California"}}],
            "offset": ("int32", -28800),
        },
        "lk": {
            "city": {"names": {"en": "Colombo", "si": "කොළඹ"}},
            "country": {"iso_code": "LK", "names": {"en": "Sri Lanka", "si": "ශ්‍රී ලංකාව"}},
            "location": {"latitude": 6.9319, "longitude": 79.8478},
            "subdivisions": [{"names": {"en": "Western Province"}}],
        },
        "reg": {"registered_country": {"iso_code": "DE", "names": {"en": "Germany"}}},
        "us2": {"country": {"iso_code": "US", "names": {"en": "United States"}}, "city": {"names": {"en": "Mountain View"}}},
    },
    "networks": [
        ("1.2.3.0/24", "au"),
        ("8.8.8.0/24", "us"),
        ("112.134.0.0/16", "lk"),
        ("203.0.113.128/25", "reg"),
        ("2001:4860::/32", "us2"),
    ],
}

if __name__ == "__main__":
    out = sys.argv[1]
    for size in (24, 28, 32):
        n = build(os.path.join(out, "test-%d.mmdb" % size), size, RECORDS)
        print("record_size=%d nodes=%d" % (size, n))
    for size in (28, 32):
        n = build(os.path.join(out, "test-%d-big.mmdb" % size), size, RECORDS, pad=True)
        print("record_size=%d (padded >16MB) nodes=%d" % (size, n))
