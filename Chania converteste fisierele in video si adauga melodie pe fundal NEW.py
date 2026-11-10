import os
import shutil
import subprocess
import sys


# ===== CONFIGURARI =====
media_directory = r"g:\De pus pe FTP 2\bucovina"
audio_path = r"g:\Muzica\Hamidshax - Yesterday (Original Mix).mp3"
output_path = r"g:\De pus pe FTP 2\bucovina\Bucuvina-Rarau_2026.mp4"

temp_root = os.path.join(media_directory, "Temp")
work_temp_dir = os.path.join(temp_root, "video_work")

TARGET_W = 1920
TARGET_H = 1080
FPS = 24
IMAGE_DURATION = 3
FADE_DURATION = 0.5
SORT_METHOD = "DATE"  # "DATE" sau "NAME"
CRF = "24"            # mai mic = calitate mai buna, fisier mai mare
PRESET = "fast"       # ultrafast, superfast, veryfast, faster, fast, medium

# Parametri pentru pista audio pastrata din videoclipuri.
AUDIO_SAMPLE_RATE = "44100"
AUDIO_CHANNELS = "2"
AUDIO_BITRATE = "192k"


def resolve_audio_path(path):
    """Returneaza calea melodiei externe doar daca este reala pe disc.

    Daca 'audio_path' este gol, doar spatii, sau indica un fisier care nu
    exista, functia returneaza sir gol. In acel caz scriptul nu mai adauga
    nicio melodie si pastreaza sonorul original din videoclipuri.
    """
    candidate = (path or "").strip().strip('"').strip("'").strip()
    if not candidate:
        return ""
    if not os.path.isfile(candidate):
        return ""
    return candidate


EXTERNAL_AUDIO = resolve_audio_path(audio_path)
KEEP_ORIGINAL_AUDIO = not EXTERNAL_AUDIO


def prepare_temp_folder():
    """Foloseste numai folderul Temp din directorul de pe G."""
    os.makedirs(temp_root, exist_ok=True)

    # Ajuta si librariile/subprocesele care folosesc temp-ul implicit.
    for key in ("TEMP", "TMP", "TMPDIR", "IMAGEIO_FFMPEG_TEMP"):
        os.environ[key] = temp_root

    root_abs = os.path.abspath(temp_root)
    work_abs = os.path.abspath(work_temp_dir)
    if not work_abs.lower().startswith(root_abs.lower() + os.sep.lower()):
        raise RuntimeError(f"Folder temporar nesigur: {work_temp_dir}")

    if os.path.exists(work_temp_dir):
        shutil.rmtree(work_temp_dir, ignore_errors=True)
    os.makedirs(work_temp_dir, exist_ok=True)


def find_ffmpeg():
    """Cauta ffmpeg in PATH, imageio-ffmpeg, apoi in locatii uzuale."""
    ffmpeg = shutil.which("ffmpeg")
    if ffmpeg:
        return ffmpeg

    try:
        import imageio_ffmpeg

        ffmpeg = imageio_ffmpeg.get_ffmpeg_exe()
        if ffmpeg and os.path.exists(ffmpeg):
            return ffmpeg
    except Exception:
        pass

    for path in (
        r"C:\ffmpeg\bin\ffmpeg.exe",
        r"C:\Program Files\ffmpeg\bin\ffmpeg.exe",
        os.path.expanduser(r"~\ffmpeg\bin\ffmpeg.exe"),
    ):
        if os.path.exists(path):
            return path

    return "ffmpeg"


def find_ffprobe(ffmpeg_path):
    ffprobe = shutil.which("ffprobe")
    if ffprobe:
        return ffprobe

    folder = os.path.dirname(ffmpeg_path)
    if folder:
        candidate = os.path.join(folder, "ffprobe.exe")
        if os.path.exists(candidate):
            return candidate

    return "ffprobe"


FFMPEG = find_ffmpeg()
FFPROBE = find_ffprobe(FFMPEG)


def run_command(cmd, description):
    print(f"    Rulare: {description}")
    sys.stdout.flush()

    result = subprocess.run(
        cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        errors="replace",
    )

    if result.returncode != 0:
        print(f"    EROARE ({result.returncode}) la: {description}")
        lines = (result.stderr or "").strip().splitlines()
        for line in lines[-18:]:
            print(f"      {line}")
        sys.stdout.flush()
        return False

    return True


def get_duration_seconds(path):
    cmd = [
        FFPROBE,
        "-v", "error",
        "-show_entries", "format=duration",
        "-of", "default=noprint_wrappers=1:nokey=1",
        path,
    ]

    try:
        result = subprocess.run(
            cmd,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            errors="replace",
        )
        if result.returncode == 0:
            value = result.stdout.strip()
            if value:
                return float(value)
    except Exception:
        pass

    return None


def has_audio_stream(path):
    """Verifica daca fisierul video contine o pista audio."""
    cmd = [
        FFPROBE,
        "-v", "error",
        "-select_streams", "a:0",
        "-show_entries", "stream=codec_type",
        "-of", "default=noprint_wrappers=1:nokey=1",
        path,
    ]

    try:
        result = subprocess.run(
            cmd,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            errors="replace",
        )
        return result.returncode == 0 and "audio" in (result.stdout or "")
    except Exception:
        return False


def silent_audio_input():
    """Sursa de liniste, folosita cand un clip nu are sonor propriu."""
    return [
        "-f", "lavfi",
        "-i", f"anullsrc=channel_layout=stereo:sample_rate={AUDIO_SAMPLE_RATE}",
    ]


def audio_encode_args():
    """Parametri identici pentru toate bucatile, ca sa se poata concatena."""
    return [
        "-c:a", "aac",
        "-b:a", AUDIO_BITRATE,
        "-ar", AUDIO_SAMPLE_RATE,
        "-ac", AUDIO_CHANNELS,
    ]


def video_filter(duration=None):
    parts = [
        f"scale={TARGET_W}:{TARGET_H}:force_original_aspect_ratio=decrease",
        f"pad={TARGET_W}:{TARGET_H}:(ow-iw)/2:(oh-ih)/2:color=black",
        f"fps={FPS}",
        "setsar=1",
    ]

    if duration and duration > FADE_DURATION * 2:
        parts.append(f"fade=t=in:st=0:d={FADE_DURATION}")
        parts.append(f"fade=t=out:st={duration - FADE_DURATION}:d={FADE_DURATION}")

    parts.append("format=yuv420p")
    return ",".join(parts)


def process_image(input_path, output_temp):
    cmd = [
        FFMPEG,
        "-y",
        "-loop", "1",
        "-framerate", str(FPS),
        "-i", input_path,
    ]

    # Imaginile nu au sonor. Cand pastram audio-ul original din videoclipuri,
    # adaugam o pista silentioasa ca toate bucatile sa aiba aceeasi structura.
    if KEEP_ORIGINAL_AUDIO:
        cmd += silent_audio_input()

    cmd += [
        "-t", str(IMAGE_DURATION),
        "-vf", video_filter(IMAGE_DURATION),
    ]

    if KEEP_ORIGINAL_AUDIO:
        cmd += ["-map", "0:v:0", "-map", "1:a:0"]
        cmd += audio_encode_args()
    else:
        cmd += ["-an"]

    cmd += [
        "-c:v", "libx264",
        "-preset", PRESET,
        "-crf", CRF,
        "-r", str(FPS),
        "-pix_fmt", "yuv420p",
        "-movflags", "+faststart",
        output_temp,
    ]
    return run_command(cmd, os.path.basename(input_path))


def process_video(input_path, output_temp):
    duration = get_duration_seconds(input_path)
    keeps_own_audio = KEEP_ORIGINAL_AUDIO and has_audio_stream(input_path)

    cmd = [
        FFMPEG,
        "-y",
        "-i", input_path,
    ]

    # Videoclip fara sonor propriu: completam cu liniste, ca sa nu rupem
    # concatenarea cu celelalte bucati care au pista audio.
    if KEEP_ORIGINAL_AUDIO and not keeps_own_audio:
        cmd += silent_audio_input()

    cmd += ["-map", "0:v:0"]

    if KEEP_ORIGINAL_AUDIO:
        cmd += ["-map", "0:a:0"] if keeps_own_audio else ["-map", "1:a:0"]

    cmd += ["-vf", video_filter(duration)]

    if KEEP_ORIGINAL_AUDIO:
        cmd += audio_encode_args()
        if not keeps_own_audio:
            # anullsrc este infinit, deci taiem la lungimea imaginii.
            cmd += ["-shortest"]
    else:
        cmd += ["-an"]

    cmd += [
        "-c:v", "libx264",
        "-preset", PRESET,
        "-crf", CRF,
        "-r", str(FPS),
        "-pix_fmt", "yuv420p",
        "-movflags", "+faststart",
        output_temp,
    ]
    return run_command(cmd, os.path.basename(input_path))


def concat_list_path():
    return os.path.join(work_temp_dir, "ffmpeg_concat_list.txt")


def write_concat_list(temp_files):
    list_path = concat_list_path()
    with open(list_path, "w", encoding="utf-8") as handle:
        for temp_file in temp_files:
            safe_path = os.path.abspath(temp_file).replace("\\", "/").replace("'", "'\\''")
            handle.write(f"file '{safe_path}'\n")
    return list_path


def concatenate_with_ffmpeg(temp_files, output_file):
    list_path = write_concat_list(temp_files)
    cmd = [
        FFMPEG,
        "-y",
        "-f", "concat",
        "-safe", "0",
        "-i", list_path,
        "-c", "copy",
        "-movflags", "+faststart",
        output_file,
    ]

    if run_command(cmd, "Concatenare clipuri"):
        return True

    print("    Incerc din nou concatenarea cu re-encodare...")
    cmd = [
        FFMPEG,
        "-y",
        "-f", "concat",
        "-safe", "0",
        "-i", list_path,
        "-c:v", "libx264",
        "-preset", PRESET,
        "-crf", CRF,
        "-r", str(FPS),
        "-pix_fmt", "yuv420p",
    ]

    if KEEP_ORIGINAL_AUDIO:
        cmd += audio_encode_args()
    else:
        cmd += ["-an"]

    cmd += [
        "-movflags", "+faststart",
        output_file,
    ]
    return run_command(cmd, "Concatenare clipuri cu re-encodare")


def add_audio_loop(video_path, audio_file, output_file):
    cmd = [
        FFMPEG,
        "-y",
        "-i", video_path,
        "-stream_loop", "-1",
        "-i", audio_file,
        "-map", "0:v:0",
        "-map", "1:a:0",
        "-c:v", "copy",
        "-c:a", "aac",
        "-b:a", AUDIO_BITRATE,
        "-shortest",
        "-movflags", "+faststart",
        output_file,
    ]
    return run_command(cmd, "Adaugare melodie pe fundal")


def media_files():
    valid_extensions = (".png", ".jpg", ".jpeg", ".mp4", ".mov", ".avi")
    output_name = os.path.basename(output_path).lower()

    files = [
        filename
        for filename in os.listdir(media_directory)
        if filename.lower().endswith(valid_extensions)
        and filename.lower() != output_name
    ]

    if SORT_METHOD == "NAME":
        files.sort()
    else:
        files.sort(key=lambda name: os.path.getmtime(os.path.join(media_directory, name)))

    return files


def audio_description():
    if EXTERNAL_AUDIO:
        return EXTERNAL_AUDIO

    raw = (audio_path or "").strip()
    if raw:
        return f"(inexistent: {audio_path}) -> pastrez sonorul original"
    return "(neconfigurat) -> pastrez sonorul original"


def print_header(files):
    total, used, free = shutil.disk_usage(temp_root)
    print("=" * 64)
    print("  CREARE VIDEO - procesare pe bucati in Temp de pe G")
    print("=" * 64)
    print(f"  Media:      {media_directory}")
    print(f"  Audio:      {audio_description()}")
    print(f"  Output:     {output_path}")
    print(f"  Temp:       {work_temp_dir}")
    print(f"  FFmpeg:     {FFMPEG}")
    print(f"  Fisiere:    {len(files)}")
    print(f"  Spatiu G:   {free / (1024 ** 3):.1f} GB liber")
    print("=" * 64)
    sys.stdout.flush()


def create_video():
    prepare_temp_folder()

    if not os.path.isdir(media_directory):
        print(f"[!] Director media inexistent: {media_directory}")
        return

    # Melodia externa lipseste sau calea este gresita: nu oprim scriptul,
    # ci pastram sonorul original din videoclipurile care se unesc.
    if KEEP_ORIGINAL_AUDIO:
        raw = (audio_path or "").strip()
        if raw:
            print(f"[!] Audio inexistent: {audio_path}")
        else:
            print("[*] Nu a fost setata nicio melodie externa.")
        print("[*] Pastrez sonorul original din videoclipuri.")

    files = media_files()
    print_header(files)

    if not files:
        print("[!] Nu am gasit fisiere media de procesat.")
        return

    temp_files = []
    failed_files = []

    for index, filename in enumerate(files, start=1):
        input_path = os.path.join(media_directory, filename)
        temp_output = os.path.join(work_temp_dir, f"part_{index:04d}.mp4")

        print(f"\n[{index}/{len(files)}] Procesez: {filename}")
        sys.stdout.flush()

        try:
            if filename.lower().endswith((".mp4", ".mov", ".avi")):
                success = process_video(input_path, temp_output)
            else:
                success = process_image(input_path, temp_output)
        except Exception as exc:
            success = False
            print(f"    EROARE: {exc}")

        if success and os.path.exists(temp_output):
            temp_files.append(temp_output)
            size_mb = os.path.getsize(temp_output) / (1024 * 1024)
            print(f"    OK ({size_mb:.1f} MB)")
        else:
            failed_files.append(filename)
            print("    Sarit - fisierul nu a putut fi procesat.")

    if not temp_files:
        print("\n[!] Nu s-a procesat niciun fisier. Oprire.")
        return

    print(f"\n[*] Concatenare {len(temp_files)} clipuri procesate...")
    concat_name = "concat_audio_original.mp4" if KEEP_ORIGINAL_AUDIO else "concat_fara_audio.mp4"
    concat_output = os.path.join(work_temp_dir, concat_name)
    if not concatenate_with_ffmpeg(temp_files, concat_output):
        print("[!] Eroare la concatenare. Temporarele au ramas pentru verificare:")
        print(f"    {work_temp_dir}")
        return

    if EXTERNAL_AUDIO:
        print("\n[*] Adaugare melodie pe fundal...")
        success = add_audio_loop(concat_output, EXTERNAL_AUDIO, output_path)
        if not success:
            print("[!] Nu am putut adauga melodia. Salvez varianta fara audio.")
            shutil.copy2(concat_output, output_path)
    else:
        print("\n[*] Salvez varianta cu sonorul original din videoclipuri...")
        shutil.copy2(concat_output, output_path)

    if failed_files:
        failed_log = os.path.join(temp_root, "fisiere_neprocesate.txt")
        with open(failed_log, "w", encoding="utf-8") as handle:
            handle.write("\n".join(failed_files))
        print(f"\n[!] {len(failed_files)} fisiere au fost sarite. Lista:")
        print(f"    {failed_log}")

    print("\n[*] Curatare fisiere temporare de lucru...")
    shutil.rmtree(work_temp_dir, ignore_errors=True)

    if os.path.exists(output_path):
        size_mb = os.path.getsize(output_path) / (1024 * 1024)
        print("\n" + "=" * 64)
        print(f"  GATA: {output_path}")
        print(f"  Dimensiune: {size_mb:.1f} MB")
        print("=" * 64)
    else:
        print("\n[!] Nu gasesc fisierul final. Verifica mesajele de mai sus.")


if __name__ == "__main__":
    create_video()
