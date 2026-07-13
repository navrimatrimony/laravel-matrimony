#!/usr/bin/env python3
"""Verify the sidecar venv has CPU-only PyTorch (not CUDA wheels)."""

from __future__ import annotations

import sys


def main() -> int:
  import torch

  version = torch.__version__
  cuda_built = bool(torch.backends.cuda.is_built())
  cuda_available = bool(torch.cuda.is_available())

  print(f"torch={version}")
  print(f"cuda_built={cuda_built}")
  print(f"cuda_available={cuda_available}")

  if cuda_built or cuda_available:
    print(
      "ERROR: GPU/CUDA PyTorch detected. Re-run install.sh to install CPU-only torch.",
      file=sys.stderr,
    )
    return 1

  print("torch_cpu_ok=true")
  return 0


if __name__ == "__main__":
  raise SystemExit(main())
