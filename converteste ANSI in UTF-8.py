# -*- coding: utf-8 -*-
"""
Converteste un fisier text din ANSI (Windows-1252 / Windows-1250 / ISO-8859-x)
in UTF-8, pastrand diacriticele si caracterele speciale.

De ce apar xAD, xFC, x96 in Notepad++:
    Fisierul este salvat intr-o codificare pe un octet (ANSI), dar este citit
    ca UTF-8. Octetii care nu formeaza o secventa UTF-8 valida sunt afisati
    bruti, in hexazecimal. Exemple din fisierul tau:
        0xFC -> u  (Munchhausen)
        0x96 -> -  (linie de dialog / en dash)
        0xAD -> cratima optionala, invizibila (soft hyphen)

Scriptul citeste octetii reali, ii decodeaza cu codificarea corecta si
scrie rezultatul in UTF-8, intr-un fisier nou, in acelasi folder.
"""

import os
import shutil
import sys
import unicodedata
from collections import Counter


# ===== CONFIGURARI =====
input_path = r"e:\Carte\BB\SURSE\AU MAI RAMAS PENTRU SUBMIT BING.txt"

# Codificarea sursa. "auto" incearca toate variantele si o alege pe cea mai
# plauzibila. Poti forta manual: "cp1252", "cp1250", "iso-8859-1",
# "iso-8859-2", "iso-8859-16".
SOURCE_ENCODING = "auto"

OUTPUT_SUFFIX = " UTF8"      # numele fisierului rezultat
WRITE_BOM = False            # True daca vrei UTF-8 cu BOM
REMOVE_SOFT_HYPHEN = False   # True sterge cratimele invizibile (0xAD)
NORMALIZE_NBSP = False       # True inlocuieste spatiul insecabil cu spatiu normal
OVERWRITE_ORIGINAL = False   # True suprascrie originalul (face intai .bak)


CANDIDATES = ["cp1252", "cp1250", "iso-8859-1", "iso-8859-2", "iso-8859-16"]

SOFT_HYPHEN = "\u00ad"
NBSP = "\u00a0"

# Caractere asteptate intr-un text romanesc / vest-european.
GOOD_CHARS = set(
    "\u0103\u0102"          # a-breve
    "\u00e2\u00c2"          # a-circumflex
    "\u00ee\u00ce"          # i-circumflex
    "\u0219\u0218\u015f\u015e"   # s-virgula / s-sedila
    "\u021b\u021a\u0163\u0162"   # t-virgula / t-sedila
    "\u00fc\u00dc\u00f6\u00d6\u00e4\u00c4\u00df"   # germana
    "\u00e9\u00e8\u00ea\u00e0\u00e7\u00c9\u00c8\u00ca\u00c0\u00c7"  # franceza
    "\u2013\u2014"          # en dash, em dash
    "\u2018\u2019\u201c\u201d\u201e\u2026"   # ghilimele, puncte de suspensie
    "\u00ab\u00bb"          # ghilimele franceze
)

# Caractere improbabile intr-un text obisnuit: semnaleaza codificare gresita.
BAD_CHARS = set(
    "\u00a4\u00a6\u00a8\u00af\u00b2\u00b3\u00b5\u00b6\u00b7\u00b8\u00b9"
    "\u00bc\u00bd\u00be\u00d7\u00f7\u00de\u00fe\u00d0\u00f0\u00d8\u00f8"
    "\u00c6\u00e6\u00a1\u00bf\u00aa\u00ba"
)


def describe_char(ch):
    """Descriere lizibila pentru un caracter decodat."""
    if ch is None:
        return "(nu se poate decoda)"
    code = ord(ch)
    category = unicodedata.category(ch)
    name = unicodedata.name(ch, "fara nume")
    if category.startswith("C"):
        return f"U+{code:04X} <control: {name}>"
    return f"{ch}  U+{code:04X}  {name}"


def decode_byte(byte_value, encoding):
    """Decodeaza un singur octet; None daca nu este valid in codificarea data."""
    try:
        return bytes([byte_value]).decode(encoding)
    except (UnicodeDecodeError, LookupError):
        return None


def score_decoding(text):
    """Scor de plauzibilitate: cat de firesc arata textul decodat."""
    score = 0
    for ch in text:
        if ch in GOOD_CHARS:
            score += 3
        elif ch in BAD_CHARS:
            score -= 4
        elif ch in (SOFT_HYPHEN, NBSP):
            score += 0
        elif unicodedata.category(ch).startswith("C") and ch not in "\r\n\t":
            score -= 8
    return score


def looks_like_utf8(raw):
    try:
        raw.decode("utf-8")
        return True
    except UnicodeDecodeError:
        return False


def detect_bom(raw):
    boms = [
        (b"\xef\xbb\xbf", "utf-8-sig"),
        (b"\xff\xfe\x00\x00", "utf-32-le"),
        (b"\x00\x00\xfe\xff", "utf-32-be"),
        (b"\xff\xfe", "utf-16-le"),
        (b"\xfe\xff", "utf-16-be"),
    ]
    for marker, name in boms:
        if raw.startswith(marker):
            return name
    return None


def report_bytes(raw):
    """Inventarul octetilor non-ASCII si cum ii interpreteaza fiecare codificare."""
    non_ascii = Counter(b for b in raw if b > 127)
    if not non_ascii:
        print("  Fisierul contine doar caractere ASCII. Nu exista ce converti.")
        return non_ascii

    print(f"  Octeti non-ASCII distincti: {len(non_ascii)}")
    print()
    header = f"  {'octet':<7}{'aparitii':<10}" + "".join(f"{enc:<22}" for enc in CANDIDATES)
    print(header)
    print("  " + "-" * (len(header) - 2))

    for byte_value, count in sorted(non_ascii.items()):
        row = f"  0x{byte_value:02X}   {count:<10}"
        for encoding in CANDIDATES:
            ch = decode_byte(byte_value, encoding)
            if ch is None:
                cell = "(invalid)"
            elif unicodedata.category(ch).startswith("C"):
                cell = f"U+{ord(ch):04X} ctrl"
            else:
                cell = f"{ch} U+{ord(ch):04X}"
            row += f"{cell:<22}"
        print(row)
    print()
    return non_ascii


def choose_encoding(raw):
    """Alege codificarea sursa: manuala daca este setata, altfel automat."""
    if SOURCE_ENCODING != "auto":
        print(f"  Codificare fortata manual: {SOURCE_ENCODING}")
        return SOURCE_ENCODING

    results = []
    for encoding in CANDIDATES:
        try:
            text = raw.decode(encoding)
        except (UnicodeDecodeError, LookupError):
            print(f"  {encoding:<14} nu poate decoda fisierul (octeti nedefiniti)")
            continue
        score = score_decoding(text)
        results.append((score, encoding, text))
        print(f"  {encoding:<14} scor de plauzibilitate: {score}")

    if not results:
        raise RuntimeError("Nicio codificare candidat nu poate decoda fisierul.")

    results.sort(key=lambda item: (-item[0], CANDIDATES.index(item[1])))
    best_score, best_encoding, _ = results[0]
    print(f"\n  -> Aleasa automat: {best_encoding}")

    tied = [enc for score, enc, _ in results if score == best_score]
    if len(tied) > 1:
        print(f"     Scor egal cu: {', '.join(e for e in tied if e != best_encoding)}")
        print("     Rezultatul este identic pentru octetii din acest fisier.")
    return best_encoding


def build_output_path():
    if OVERWRITE_ORIGINAL:
        return input_path
    folder = os.path.dirname(os.path.abspath(input_path))
    stem, extension = os.path.splitext(os.path.basename(input_path))
    return os.path.join(folder, f"{stem}{OUTPUT_SUFFIX}{extension}")


def preview(text, limit=6):
    """Arata primele linii care contin caractere non-ASCII."""
    shown = 0
    for number, line in enumerate(text.splitlines(), start=1):
        if shown >= limit:
            break
        if any(ord(ch) > 127 for ch in line):
            visible = line.replace(SOFT_HYPHEN, "[-]").replace(NBSP, "[ ]")
            if len(visible) > 100:
                visible = visible[:100] + "..."
            print(f"    linia {number:<5} {visible}")
            shown += 1
    if shown == 0:
        print("    (nicio linie cu caractere speciale)")


def convert():
    print("=" * 74)
    print("  CONVERSIE ANSI -> UTF-8")
    print("=" * 74)

    if not os.path.isfile(input_path):
        print(f"[!] Fisierul nu exista: {input_path}")
        return False

    with open(input_path, "rb") as handle:
        raw = handle.read()

    size_kb = len(raw) / 1024
    print(f"  Fisier: {input_path}")
    print(f"  Marime: {size_kb:.1f} KB ({len(raw)} octeti)")
    print()

    bom = detect_bom(raw)
    if bom:
        print(f"[!] Fisierul are deja BOM de {bom}. Nu este ANSI.")
        print("    Deschide-l cu acea codificare, nu are nevoie de conversie.")
        return False

    if looks_like_utf8(raw) and any(b > 127 for b in raw):
        print("[!] Fisierul este DEJA UTF-8 valid.")
        print("    Daca il convertesti din ANSI acum, diacriticele se vor strica")
        print("    (ar aparea 'Ã¢', 'Ã®' etc). Opresc conversia.")
        return False

    print("--- Inventar octeti non-ASCII ---")
    non_ascii = report_bytes(raw)
    if not non_ascii:
        return False

    print("--- Alegere codificare sursa ---")
    encoding = choose_encoding(raw)
    print()

    text = raw.decode(encoding)

    changes = []
    if REMOVE_SOFT_HYPHEN and SOFT_HYPHEN in text:
        count = text.count(SOFT_HYPHEN)
        text = text.replace(SOFT_HYPHEN, "")
        changes.append(f"{count} cratime invizibile sterse")
    if NORMALIZE_NBSP and NBSP in text:
        count = text.count(NBSP)
        text = text.replace(NBSP, " ")
        changes.append(f"{count} spatii insecabile inlocuite")

    print("--- Previzualizare rezultat ---")
    preview(text)
    print()

    soft_count = text.count(SOFT_HYPHEN)
    if soft_count and not REMOVE_SOFT_HYPHEN:
        print(f"[!] Textul contine {soft_count} cratime invizibile (0xAD),")
        print("    de exemplu in 'individua[-]lizarii'. Sunt pastrate ca in original.")
        print("    Pune REMOVE_SOFT_HYPHEN = True daca vrei sa fie sterse.")
        print()

    output_path = build_output_path()

    if OVERWRITE_ORIGINAL:
        backup_path = input_path + ".bak"
        shutil.copy2(input_path, backup_path)
        print(f"[*] Copie de siguranta: {backup_path}")

    target_encoding = "utf-8-sig" if WRITE_BOM else "utf-8"
    with open(output_path, "w", encoding=target_encoding, newline="") as handle:
        handle.write(text)

    for change in changes:
        print(f"[*] {change}")

    print("=" * 74)
    print(f"  GATA: {output_path}")
    print(f"  Sursa:     {encoding}")
    print(f"  Rezultat:  {'UTF-8 cu BOM' if WRITE_BOM else 'UTF-8 fara BOM'}")
    print(f"  Marime:    {os.path.getsize(output_path) / 1024:.1f} KB")
    print("=" * 74)
    return True


if __name__ == "__main__":
    ok = convert()
    sys.exit(0 if ok else 1)
