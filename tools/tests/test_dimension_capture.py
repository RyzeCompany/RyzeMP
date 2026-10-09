#!/usr/bin/env python3
"""Isolated synthetic fixtures for tools/analyze_dimension_capture.py."""
import base64
import importlib.util
import json
import tempfile
import unittest
import zlib
from pathlib import Path

ANALYZER = Path(__file__).resolve().parents[1] / "analyze_dimension_capture.py"
spec = importlib.util.spec_from_file_location("dimension_capture_analyzer", ANALYZER)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def uvar(x):
    b = bytearray()
    while x >= 128:
        b.append((x & 0x7f) | 0x80)
        x >>= 7
    b.append(x)
    return bytes(b)


def signed(x):
    return uvar((x << 1) ^ (x >> 31))


def mkchunk():
    # Nether subchunk version 8, 0 layers, 8 uniform biome palettes, 0 border blocks.
    terrain = b"\x08\x00" + b"\x01\x00" * 8 + b"\x00"
    return (
        uvar(58) + signed(0) + signed(0) + signed(1) + uvar(1) +
        b"\x00\x00\x00" + uvar(len(terrain)) + terrain
    )


class CaptureAnalyzerTest(unittest.TestCase):
    def test_valid_2193_nether_chunk(self):
        p = module.audit_packet(mkchunk())
        self.assertEqual(58, p["id"])
        self.assertEqual(8, p["chunk"]["biome_palettes"])
        self.assertEqual(0, p["chunk"]["tile_nbt_bytes"])

    def test_bad_biome_header_fails(self):
        data = bytearray(mkchunk())
        data[-3] = 0xFE
        with self.assertRaises(module.DecodeError):
            module.audit_packet(bytes(data))

    def test_change_dimension_fixed_uint32(self):
        import struct
        p = uvar(61) + signed(1) + struct.pack("<fff", .5, 116, 4.5) + b"\x00" + struct.pack("<I", 42)
        self.assertEqual(42, module.audit_packet(p)["screen_id"])
        with self.assertRaises(module.DecodeError):
            module.audit_packet(p[:-1])

    def test_compressed_batch_and_report(self):
        packet = mkchunk()
        raw = uvar(len(packet)) + packet
        compressor = zlib.compressobj(6, zlib.DEFLATED, -15)
        encoded = b"\x00" + compressor.compress(raw) + compressor.flush()
        self.assertEqual([packet], module.decode_frame(encoded))
        with tempfile.TemporaryDirectory() as td:
            path = Path(td) / "capture.jsonl"
            path.write_text(
                json.dumps({"kind": "start", "protocol": 2193, "dimension": 1, "time": 100}) + "\n" +
                json.dumps({"kind": "outbound_batch", "time": 100.01, "bytes": len(encoded),
                            "base64": base64.b64encode(encoded).decode("ascii")}) + "\n" +
                json.dumps({"kind": "end", "reason": "client_disconnected"}) + "\n",
                encoding="utf-8"
            )
            out = module.analyze(path)
            self.assertEqual([], out["errors"])
            self.assertEqual(1, out["chunks_detected"])
            self.assertEqual(1, out["packet_counts"]["LevelChunk"])
            self.assertNotIn("base64", json.dumps(out))

    def test_truncated_batch_is_rejected(self):
        with self.assertRaises(module.DecodeError):
            module.decode_frame(b"\x00\x01")


if __name__ == "__main__":
    unittest.main()
