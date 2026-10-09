#!/usr/bin/env python3
"""Audit Bedrock protocol 2193 captured outbound compressed batches.

Usage:
  python3 tools/analyze_dimension_capture.py dimension-captures/dim2193-*.jsonl \
      > dimension-report.json

Only emits metadata, counts, hashes, packet order, and structural errors.
Never includes encoded packet bodies, sign NBT, chat, or credentials.
"""
import base64
import collections
import hashlib
import json
import struct
import sys
import zlib
from pathlib import Path

MAX_RAW_FRAME = 12 * 1024 * 1024
MAX_PACKET = 12 * 1024 * 1024
PACKET_NAMES = {
    19: "MovePlayer",
    36: "PlayerAction",
    43: "SetSpawnPosition",
    58: "LevelChunk",
    60: "SetTime",
    61: "ChangeDimension",
    121: "NetworkChunkPublisherUpdate",
}

class DecodeError(ValueError):
    pass

class Reader:
    def __init__(self, data):
        self.data = memoryview(data)
        self.at = 0

    def read(self, count):
        if count < 0 or self.at + count > len(self.data):
            raise DecodeError(f"Truncated offset={self.at} need={count} remaining={len(self.data)-self.at}")
        v = self.data[self.at:self.at+count].tobytes()
        self.at += count
        return v

    def byte(self):
        return self.read(1)[0]

    def uvar(self):
        v = 0
        for i in range(10):
            b = self.byte()
            if i == 9 and b > 1:
                raise DecodeError("varint overflow")
            v |= (b & 0x7f) << (7*i)
            if b < 128:
                return v
        raise DecodeError("varint overlong")

    def svar(self):
        n = self.uvar()
        return (n >> 1) ^ -(n & 1)

    def vec3(self):
        return struct.unpack("<fff", self.read(12))

    def left(self):
        return len(self.data) - self.at


def read_palette(r, cells, hashed=False):
    header = r.byte()
    bits = header >> 1
    if (header & 1) != 1 or bits not in (0, 1, 2, 3, 4, 5, 6, 8, 16):
        raise DecodeError(f"Invalid palette header {header:#x} at offset {r.at - 1}")
    if bits:
        values_per_word = 32 // bits
        words = (cells + values_per_word - 1) // values_per_word
        r.read(words * 4)
        palette_len = r.svar()
        if not 1 <= palette_len <= cells:
            raise DecodeError(f"Invalid palette size {palette_len} at offset {r.at}")
    else:
        palette_len = 1
    # Hashed block IDs can be negative when interpreted as signed 32-bit.
    values = [r.svar() for _ in range(palette_len)]
    if not hashed and any(x < 0 for x in values):
        raise DecodeError("Negative biome ID")
    return {"bits": bits, "values": palette_len}


def audit_chunk_payload(chunk_bytes, dimension, subcount):
    r = Reader(chunk_bytes)
    if subcount > 32:
        raise DecodeError(f"Suspicious subchunk count {subcount}")
    for y in range(subcount):
        version = r.byte()
        if version not in (8, 9):
            raise DecodeError(f"Unexpected subchunk version {version} at y={y}")
        layers = r.byte()
        if layers > 2:
            raise DecodeError(f"Unexpected subchunk layer count {layers} at y={y}")
        if version == 9:
            r.byte()  # signed subchunk Y (network byte)
        for _ in range(layers):
            read_palette(r, 4096, hashed=True)

    biome_counts = {0: 24, 1: 8, 2: 16}
    if dimension not in biome_counts:
        raise DecodeError(f"Unsupported dimension {dimension}")
    for _ in range(biome_counts[dimension]):
        read_palette(r, 4096, hashed=False)

    border_count = r.byte()
    if border_count != 0:
        raise DecodeError(f"Border-block count {border_count}: unsupported, may crash client")
    # Remaining data is a concatenated NBT compound sequence for tile entities.
    return {"subchunks": subcount, "biome_palettes": biome_counts[dimension],
            "tile_nbt_bytes": r.left(), "payload_sha256": hashlib.sha256(chunk_bytes).hexdigest()}


def audit_packet(data):
    r = Reader(data)
    header = r.uvar()
    pid = header & 0x3ff
    record = {"id": pid, "name": PACKET_NAMES.get(pid, f"pid:{pid}"), "bytes": len(data)}
    if pid == 61:
        dimension = r.svar()
        r.vec3()
        respawn = r.byte()
        screen_id = struct.unpack("<I", r.read(4))[0]
        if r.left():
            raise DecodeError(f"ChangeDimension has {r.left()} trailing bytes")
        record.update({"dimension": dimension, "respawn": respawn, "screen_id": screen_id})
    elif pid == 58:
        x, z = r.svar(), r.svar()
        dim, sub = r.svar(), r.uvar()
        opt = r.byte()
        if opt not in (0, 1):
            raise DecodeError("Invalid LevelChunk optional limit flag")
        if opt:
            r.svar()
        cached = r.byte()
        if cached not in (0, 1):
            raise DecodeError("Invalid cache flag")
        blob_count = r.uvar()
        if blob_count > 2048:
            raise DecodeError("Suspicious hash count")
        r.read(blob_count * 8)
        length = r.uvar()
        if length > MAX_PACKET:
            raise DecodeError(f"Chunk payload exceeds limit {length}")
        payload = r.read(length)
        if r.left():
            raise DecodeError(f"LevelChunk has {r.left()} trailing bytes")
        record.update({"dimension": dim, "x": x, "z": z, "subchunks": sub,
                       "used_cache": bool(cached), "blob_count": blob_count,
                       "chunk": audit_chunk_payload(payload, dim, sub)})
    elif pid == 19:
        record["note"] = "teleport/player movement packet; payload redacted"
    return record


def decode_frame(encoded):
    if len(encoded) > MAX_RAW_FRAME:
        raise DecodeError("Compressed batch exceeds size limit")
    if not encoded:
        raise DecodeError("Empty batch")
    codec = encoded[0]
    if codec == 0:
        try:
            raw = zlib.decompress(encoded[1:], -15)
        except zlib.error:
            try:
                raw = zlib.decompress(encoded[1:])
            except zlib.error as exc:
                raise DecodeError("Truncated or invalid zlib-compressed frame") from exc
    elif codec == 255:
        raw = encoded[1:]
    elif codec == 1:
        try:
            import snappy
        except ImportError as exc:
            raise DecodeError("Snappy selected: install python-snappy on VPS") from exc
        raw = snappy.decompress(encoded[1:])
    else:
        raise DecodeError(f"Unknown compression codec {codec}")
    if len(raw) > MAX_RAW_FRAME:
        raise DecodeError("Decompressed batch exceeds size limit")
    r = Reader(raw)
    packets = []
    while r.left():
        size = r.uvar()
        if not 0 < size <= MAX_PACKET:
            raise DecodeError(f"Bad packet size {size}")
        packets.append(r.read(size))
        if len(packets) > 2048:
            raise DecodeError("Too many packets in one batch")
    return packets


def analyze(path):
    summary = {
        "file": path.name, "events": [], "packet_counts": {},
        "chunks": [], "errors": [], "outbound_batches": 0
    }
    counts = collections.Counter()
    first_time = None
    for number, raw_line in enumerate(path.read_text(encoding="utf-8").splitlines(), start=1):
        try:
            row = json.loads(raw_line)
            kind = row.get("kind")
            if kind == "start":
                first_time = row["time"]
                summary["protocol"] = row["protocol"]
                summary["dimension"] = row["dimension"]
            elif kind == "outbound_batch":
                summary["outbound_batches"] += 1
                blob = base64.b64decode(row["base64"], validate=True)
                if len(blob) != row["bytes"]:
                    raise DecodeError("Base64 size mismatch")
                packets = decode_frame(blob)
                for frame in packets:
                    pkt = audit_packet(frame)
                    counts[pkt["name"]] += 1
                    when = round(row["time"] - first_time, 4) if first_time else None
                    e = {"seconds": when, "id": pkt["id"], "name": pkt["name"], "bytes": pkt["bytes"]}
                    for k in ("dimension", "subchunks", "screen_id"):
                        if k in pkt:
                            e[k] = pkt[k]
                    if len(summary["events"]) < 250:
                        summary["events"].append(e)
                    if "chunk" in pkt:
                        summary["chunks"].append({"seconds": when, "dimension": pkt["dimension"],
                           "x": pkt["x"], "z": pkt["z"], "subchunks": pkt["subchunks"],
                           **pkt["chunk"]})
            elif kind == "end":
                summary["capture_end"] = row.get("reason")
        except Exception as exc:
            summary["errors"].append({"line": number, "problem": str(exc)})
            if len(summary["errors"]) > 40:
                break
    summary["packet_counts"] = dict(sorted(counts.items()))
    summary["chunks_detected"] = len(summary["chunks"])
    summary["status"] = "capture_has_errors" if summary["errors"] else "parsed_with_no_structural_errors"
    summary["warning"] = (
        "Passing this structural parser does not guarantee Minecraft client validity, "
        "nor prove the client received any batch. No packet data included in report."
    )
    return summary


def main():
    if len(sys.argv) != 2:
        print("Usage: python3 tools/analyze_dimension_capture.py <capture.jsonl> > report.json", file=sys.stderr)
        return 2
    path = Path(sys.argv[1])
    if not path.is_file():
        print(f"Not found: {path}", file=sys.stderr)
        return 2
    result = analyze(path)
    print(json.dumps(result, indent=2, sort_keys=True))
    return 1 if result["errors"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
