#!/usr/bin/env python3
"""Compile the plugin's PO catalogs to GNU MO files using only Python's stdlib."""
from __future__ import annotations

import argparse
import ast
import pathlib
import struct
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
LOCALES = ROOT / "locales"


def parse_po(path: pathlib.Path) -> dict[bytes, bytes]:
    entries: list[dict[str, object]] = []
    current: dict[str, object] = {}
    active: str | None = None

    def flush() -> None:
        nonlocal current, active
        if not current:
            return
        msgid = str(current.get("msgid", ""))
        if "msgid_plural" in current:
            original = (msgid + "\0" + str(current["msgid_plural"])).encode("utf-8")
            translations = current.get("msgstr_plural", {})
            if not isinstance(translations, dict):
                raise ValueError(f"Invalid plural translation in {path}")
            values = [str(translations.get(i, "")) for i in range(max(translations.keys(), default=-1) + 1)]
            translated = "\0".join(values).encode("utf-8")
        else:
            original = msgid.encode("utf-8")
            translated = str(current.get("msgstr", "")).encode("utf-8")
        if original in dict((item[0], item[1]) for item in entries):
            raise ValueError(f"Duplicate msgid in {path}: {msgid!r}")
        entries.append((original, translated))
        current = {}
        active = None

    for line_number, raw_line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        line = raw_line.strip()
        if not line or line.startswith("#"):
            continue
        if line.startswith("msgid "):
            flush()
            current["msgid"] = ast.literal_eval(line[6:])
            active = "msgid"
        elif line.startswith("msgid_plural "):
            current["msgid_plural"] = ast.literal_eval(line[13:])
            active = "msgid_plural"
        elif line.startswith("msgstr["):
            index_text, value_text = line[7:].split("]", 1)
            translations = current.setdefault("msgstr_plural", {})
            if not isinstance(translations, dict):
                raise ValueError(f"Invalid msgstr plural at {path}:{line_number}")
            translations[int(index_text)] = ast.literal_eval(value_text.strip())
            active = f"msgstr_plural:{int(index_text)}"
        elif line.startswith("msgstr "):
            current["msgstr"] = ast.literal_eval(line[7:])
            active = "msgstr"
        elif line.startswith('"') and active:
            value = ast.literal_eval(line)
            if active == "msgid" or active == "msgid_plural" or active == "msgstr":
                current[active] = str(current.get(active, "")) + value
            elif active.startswith("msgstr_plural:"):
                index = int(active.split(":", 1)[1])
                translations = current["msgstr_plural"]
                translations[index] = str(translations.get(index, "")) + value
        else:
            raise ValueError(f"Unsupported PO syntax at {path}:{line_number}: {line}")
    flush()

    result = dict(entries)
    if b"" not in result:
        raise ValueError(f"Missing Gettext metadata entry in {path}")
    if sum(1 for original in result if original == b"") != 1:
        raise ValueError(f"Expected exactly one empty msgid in {path}")
    return result


def compile_mo(messages: dict[bytes, bytes]) -> bytes:
    originals = sorted(messages)
    ids = b""
    strs = b""
    offsets_original: list[tuple[int, int]] = []
    offsets_translated: list[tuple[int, int]] = []

    for original in originals:
        offsets_original.append((len(original), len(ids)))
        ids += original + b"\0"
    for original in originals:
        translated = messages[original]
        offsets_translated.append((len(translated), len(strs)))
        strs += translated + b"\0"

    count = len(originals)
    header_size = 7 * 4
    original_table_offset = header_size
    translated_table_offset = original_table_offset + count * 8
    original_data_offset = translated_table_offset + count * 8
    translated_data_offset = original_data_offset + len(ids)

    output = bytearray()
    output += struct.pack("<7I", 0x950412DE, 0, count, original_table_offset, translated_table_offset, 0, 0)
    for length, offset in offsets_original:
        output += struct.pack("<2I", length, original_data_offset + offset)
    for length, offset in offsets_translated:
        output += struct.pack("<2I", length, translated_data_offset + offset)
    output += ids + strs
    return bytes(output)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true", help="fail if MO files differ from compiled PO files")
    args = parser.parse_args()

    po_files = sorted(LOCALES.glob("*.po"))
    if not po_files:
        print("No PO catalogs found", file=sys.stderr)
        return 1

    expected_locales = {"es_ES", "es_MX", "fr_FR"}
    found_locales = {path.stem for path in po_files}
    if found_locales != expected_locales:
        print(f"Unexpected PO locale set: {sorted(found_locales)}", file=sys.stderr)
        return 1

    failed = False
    for po_path in po_files:
        mo_path = po_path.with_suffix(".mo")
        compiled = compile_mo(parse_po(po_path))
        if args.check:
            if not mo_path.exists() or mo_path.read_bytes() != compiled:
                print(f"Outdated MO catalog: {mo_path.relative_to(ROOT)}", file=sys.stderr)
                failed = True
            else:
                print(f"OK {mo_path.relative_to(ROOT)}")
        else:
            mo_path.write_bytes(compiled)
            print(f"Wrote {mo_path.relative_to(ROOT)}")
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
