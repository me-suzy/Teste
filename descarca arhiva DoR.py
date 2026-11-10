# -*- coding: utf-8 -*-
"""
Descarca toata arhiva revistei DoR (PDF) de pe
https://www.dor.ro/arhiva-revistei-in-pdf/ in folderul G:\\DOR.

Cum functioneaza:
    Pagina de arhiva are 51 de linkuri catre Dropbox. Butonul "Download" din
    interfata Dropbox nu poate fi apasat de un script, iar vechiul truc cu
    "?dl=1" nu mai merge: Dropbox raspunde cu o pagina HTML, nu cu PDF-ul.

    Solutia este domeniul de continut direct:
        https://www.dropbox.com/s/<id>/<nume>.pdf?dl=0
        -> https://dl.dropboxusercontent.com/s/<id>/<nume>.pdf
    Acesta raspunde cu application/pdf si accepta cereri Range, deci
    descarcarile intrerupte pot fi reluate de unde au ramas.

Scriptul nu are nevoie de biblioteci externe (doar stdlib).
"""

import json
import os
import re
import sys
import time
import unicodedata
import urllib.error
import urllib.request
from html import unescape
from urllib.parse import urlsplit, urlunsplit, parse_qsl, urlencode


# ===== CONFIGURARI =====
ARCHIVE_URL = "https://www.dor.ro/arhiva-revistei-in-pdf/"
DEST_DIR = r"G:\DOR"

MAX_RETRIES = 4          # incercari per fisier
TIMEOUT = 120            # secunde pentru conectare/citire
CHUNK = 256 * 1024       # marimea blocului citit
PAUSE_BETWEEN = 1.0      # pauza intre fisiere, ca sa nu impovaram serverul
MIN_PDF_BYTES = 50_000   # sub atat, aproape sigur nu e revista

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36"
)


def fetch(url, extra_headers=None):
    """Cerere GET simpla, cu User-Agent de browser."""
    headers = {"User-Agent": USER_AGENT}
    if extra_headers:
        headers.update(extra_headers)
    request = urllib.request.Request(url, headers=headers)
    return urllib.request.urlopen(request, timeout=TIMEOUT)


def get_archive_links():
    """Extrage linkurile Dropbox si eticheta fiecarui numar din pagina arhivei."""
    with fetch(ARCHIVE_URL) as response:
        charset = response.headers.get_content_charset() or "utf-8"
        page = response.read().decode(charset, errors="replace")

    pattern = re.compile(
        r'<a\b[^>]*href="(https://(?:www\.)?dropbox\.com/[^"]+)"[^>]*>(.*?)</a>',
        re.IGNORECASE | re.DOTALL,
    )

    seen = set()
    items = []
    for url, inner in pattern.findall(page):
        url = unescape(url)
        identity = url.split("?")[0]
        if identity in seen:
            continue
        seen.add(identity)
        label = unescape(re.sub(r"<[^>]+>", "", inner)).strip()
        items.append({"label": label, "page_url": url})
    return items


def to_direct_url(dropbox_url):
    """www.dropbox.com/... -> dl.dropboxusercontent.com/... (continut brut).

    Parametrul dl= este scos; rlkey= trebuie pastrat, altfel linkurile noi
    de tip /scl/fi/ raspund cu 404.
    """
    parts = urlsplit(dropbox_url)
    query = [(k, v) for k, v in parse_qsl(parts.query) if k.lower() != "dl"]
    return urlunsplit(
        ("https", "dl.dropboxusercontent.com", parts.path, urlencode(query), "")
    )


def strip_diacritics(text):
    decomposed = unicodedata.normalize("NFKD", text)
    return "".join(ch for ch in decomposed if not unicodedata.combining(ch))


def issue_sort_key(label):
    """Cheie de sortare dupa numarul revistei: #7.1 inaintea lui #7.2 si #8."""
    match = re.search(r"#\s*(\d+)(?:\.(\d+))?", label)
    if not match:
        return (9999, 0)
    return (int(match.group(1)), int(match.group(2) or 0))


def make_filename(label):
    """'DoR #7.1 - Toamna 2011' -> 'DoR-07.1 - Toamna 2011.pdf'."""
    clean = strip_diacritics(label)
    clean = clean.replace("\u2013", "-").replace("\u2014", "-")

    match = re.search(r"#\s*(\d+)(?:\.(\d+))?", clean)
    if match:
        number = f"{int(match.group(1)):02d}"
        if match.group(2):
            number += "." + match.group(2)
        rest = clean[match.end():].strip(" -\u2013")
        name = f"DoR-{number}" + (f" - {rest}" if rest else "")
    else:
        name = clean or "DoR"

    # Caractere interzise in numele de fisier pe Windows.
    name = re.sub(r'[\\/:*?"<>|]', "-", name)
    name = re.sub(r"\s+", " ", name).strip(" .")
    return name + ".pdf"


def remote_size(url):
    """Marimea fisierului de pe server, sau None daca nu o putem afla."""
    try:
        request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
        request.get_method = lambda: "HEAD"
        with urllib.request.urlopen(request, timeout=TIMEOUT) as response:
            length = response.headers.get("Content-Length")
            return int(length) if length else None
    except Exception:
        return None


def looks_like_pdf(path):
    try:
        with open(path, "rb") as handle:
            return handle.read(5) == b"%PDF-"
    except OSError:
        return False


def human(num_bytes):
    if num_bytes is None:
        return "necunoscut"
    value = float(num_bytes)
    for unit in ("B", "KB", "MB", "GB"):
        if value < 1024 or unit == "GB":
            return f"{value:.1f} {unit}"
        value /= 1024


def download(url, destination, expected_size):
    """Descarca cu reluare de unde a ramas (HTTP Range) si reincercari."""
    partial = destination + ".part"

    for attempt in range(1, MAX_RETRIES + 1):
        have = os.path.getsize(partial) if os.path.exists(partial) else 0

        if expected_size and have == expected_size:
            break
        if expected_size and have > expected_size:
            os.remove(partial)
            have = 0

        headers = {"Range": f"bytes={have}-"} if have else None

        try:
            with fetch(url, headers) as response:
                content_type = (response.headers.get("Content-Type") or "").lower()
                if "application/pdf" not in content_type and "octet-stream" not in content_type:
                    raise RuntimeError(f"serverul a raspuns cu {content_type or 'tip necunoscut'}")

                # Daca serverul ignora Range (200 in loc de 206), reluam de la zero.
                mode = "ab" if (have and response.status == 206) else "wb"
                if mode == "wb":
                    have = 0

                total = expected_size
                if total is None:
                    length = response.headers.get("Content-Length")
                    if length:
                        total = have + int(length)

                with open(partial, mode) as handle:
                    while True:
                        block = response.read(CHUNK)
                        if not block:
                            break
                        handle.write(block)
                        have += len(block)
                        if total:
                            percent = have * 100 // total
                            sys.stdout.write(
                                f"\r      {percent:3d}%  {human(have)} / {human(total)}   "
                            )
                        else:
                            sys.stdout.write(f"\r      {human(have)}   ")
                        sys.stdout.flush()
                sys.stdout.write("\r" + " " * 50 + "\r")

            if expected_size and os.path.getsize(partial) != expected_size:
                raise RuntimeError("marime incompleta dupa descarcare")

            if not looks_like_pdf(partial):
                os.remove(partial)
                raise RuntimeError("fisierul descarcat nu este PDF")

            os.replace(partial, destination)
            return True, None

        except Exception as exc:
            if attempt == MAX_RETRIES:
                return False, str(exc)
            wait = 2 ** attempt
            print(f"      incercarea {attempt} a esuat ({exc}); reiau in {wait}s...")
            time.sleep(wait)

    if os.path.exists(partial) and looks_like_pdf(partial):
        os.replace(partial, destination)
        return True, None
    return False, "nu s-a putut descarca"


def main():
    print("=" * 70)
    print("  DESCARCARE ARHIVA DoR -> PDF")
    print("=" * 70)
    print(f"  Sursa:      {ARCHIVE_URL}")
    print(f"  Destinatie: {DEST_DIR}")
    print()

    try:
        os.makedirs(DEST_DIR, exist_ok=True)
    except OSError as exc:
        print(f"[!] Nu pot crea folderul {DEST_DIR}: {exc}")
        return 1

    print("[*] Citesc pagina arhivei...")
    try:
        items = get_archive_links()
    except Exception as exc:
        print(f"[!] Nu pot citi pagina arhivei: {exc}")
        return 1

    if not items:
        print("[!] Nu am gasit niciun link Dropbox. Structura paginii s-a schimbat.")
        return 1

    items.sort(key=lambda item: issue_sort_key(item["label"]))
    print(f"[*] Am gasit {len(items)} numere.\n")

    downloaded = skipped = failed = 0
    failures = []
    total_bytes = 0

    for index, item in enumerate(items, start=1):
        label = item["label"]
        filename = make_filename(label)
        destination = os.path.join(DEST_DIR, filename)
        direct_url = to_direct_url(item["page_url"])

        print(f"[{index}/{len(items)}] {label}")
        print(f"      -> {filename}")

        if os.path.exists(destination) and looks_like_pdf(destination):
            size = os.path.getsize(destination)
            if size >= MIN_PDF_BYTES:
                print(f"      exista deja ({human(size)}), sar peste\n")
                skipped += 1
                total_bytes += size
                continue

        expected = remote_size(direct_url)
        ok, error = download(direct_url, destination, expected)

        if ok:
            size = os.path.getsize(destination)
            total_bytes += size
            downloaded += 1
            print(f"      OK  {human(size)}\n")
        else:
            failed += 1
            failures.append((label, error))
            print(f"      ESEC: {error}\n")

        time.sleep(PAUSE_BETWEEN)

    print("=" * 70)
    print(f"  Descarcate: {downloaded}    Sarite (existau): {skipped}    Esuate: {failed}")
    print(f"  Total pe disc: {human(total_bytes)}")
    print(f"  Folder: {DEST_DIR}")
    print("=" * 70)

    if failures:
        log_path = os.path.join(DEST_DIR, "_esuate.txt")
        with open(log_path, "w", encoding="utf-8") as handle:
            for label, error in failures:
                handle.write(f"{label}\t{error}\n")
        print(f"\n[!] Lista numerelor esuate: {log_path}")
        print("    Ruleaza scriptul din nou; cele descarcate deja vor fi sarite.")

    return 0 if failed == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
