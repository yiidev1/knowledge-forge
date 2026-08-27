#!/usr/bin/env python3
"""Offline speaker diarization for the Audio-to-Text worker.

Reads a 16 kHz mono WAV and prints one JSON object to stdout:

    {"segments": [{"start": 0.0, "end": 1.82, "speaker": 0}, ...]}

Everything runs locally through sherpa-onnx. No audio, and no derived data, leaves this machine.

Threading is pinned to a single CPU thread on purpose. sherpa-onnx already defaults both of ONNX
Runtime's thread pools to one, but a default is not a contract, so `--num-threads` is passed
explicitly to both models; the caller additionally sets OMP_NUM_THREADS and friends in the child
environment. The whole audio pipeline is meant to occupy exactly one core.

Failure policy: any problem exits non-zero with a message on stderr. The PHP caller treats that as a
diarization failure and records it against the job's speaker-separation status — the transcript
itself has already been committed to the database by then and is never at risk.
"""

from __future__ import annotations

import argparse
import json
import sys
import wave


def read_wave(path: str) -> tuple[list[float], int]:
    """Return (samples in [-1, 1], sample_rate).

    Deliberately uses the standard library rather than numpy or soundfile: the file has just been
    produced by ffmpeg as 16 kHz mono PCM s16le, so the one format this needs to handle is the one
    format it will ever be given, and an extra dependency is an extra thing to install and pin.
    """
    with wave.open(path, "rb") as wav:
        if wav.getnchannels() != 1:
            raise ValueError(f"expected mono audio, got {wav.getnchannels()} channels")
        if wav.getsampwidth() != 2:
            raise ValueError(f"expected 16-bit samples, got {wav.getsampwidth() * 8}-bit")

        rate = wav.getframerate()
        frames = wav.readframes(wav.getnframes())

    import array

    pcm = array.array("h")
    pcm.frombytes(frames)
    if sys.byteorder == "big":
        pcm.byteswap()

    return [sample / 32768.0 for sample in pcm], rate


def main() -> int:
    parser = argparse.ArgumentParser(description="Local speaker diarization via sherpa-onnx.")
    parser.add_argument("--audio", required=True)
    parser.add_argument("--segmentation-model", required=True)
    parser.add_argument("--embedding-model", required=True)
    parser.add_argument(
        "--max-speakers",
        type=int,
        default=2,
        help="0 clusters by distance threshold instead of a fixed count.",
    )
    parser.add_argument("--num-threads", type=int, default=1)
    parser.add_argument(
        "--cluster-threshold",
        type=float,
        default=0.5,
        help="Only used when --max-speakers is 0.",
    )
    args = parser.parse_args()

    try:
        import sherpa_onnx
    except ImportError as exc:  # pragma: no cover - environment-dependent
        print(f"sherpa-onnx is not installed: {exc}", file=sys.stderr)
        return 3

    samples, sample_rate = read_wave(args.audio)
    if not samples:
        print("the audio file contained no samples", file=sys.stderr)
        return 4

    threads = max(1, args.num_threads)

    config = sherpa_onnx.OfflineSpeakerDiarizationConfig(
        segmentation=sherpa_onnx.OfflineSpeakerSegmentationModelConfig(
            pyannote=sherpa_onnx.OfflineSpeakerSegmentationPyannoteModelConfig(
                model=args.segmentation_model
            ),
            num_threads=threads,
            provider="cpu",
        ),
        embedding=sherpa_onnx.SpeakerEmbeddingExtractorConfig(
            model=args.embedding_model,
            num_threads=threads,
            provider="cpu",
        ),
        clustering=sherpa_onnx.FastClusteringConfig(
            # A fixed count is the reliable choice for a two-party order call; passing 0 lets the
            # clusterer decide, which is what a recording with a third voice needs.
            num_clusters=args.max_speakers if args.max_speakers > 0 else -1,
            threshold=args.cluster_threshold,
        ),
    )

    if not config.validate():
        print("the diarization configuration was rejected by sherpa-onnx", file=sys.stderr)
        return 5

    diarization = sherpa_onnx.OfflineSpeakerDiarization(config)

    if diarization.sample_rate != sample_rate:
        print(
            f"model expects {diarization.sample_rate} Hz, audio is {sample_rate} Hz",
            file=sys.stderr,
        )
        return 6

    result = diarization.process(samples).sort_by_start_time()

    segments = [
        {"start": float(s.start), "end": float(s.end), "speaker": int(s.speaker)}
        for s in result
        if float(s.end) > float(s.start)
    ]

    json.dump({"segments": segments}, sys.stdout, ensure_ascii=False)
    sys.stdout.write("\n")

    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:  # noqa: BLE001 - the exit code is the contract, not the traceback
        print(f"diarization failed: {exc}", file=sys.stderr)
        sys.exit(1)
