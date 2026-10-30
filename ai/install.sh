#!/bin/bash
# ShuttleSync AI Analysis Engine - Installation Script
# Run this on the aaPanel server to set up Python environment

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
VENV_DIR="$SCRIPT_DIR/venv"
PYTHON_BIN="python3"

echo "=== ShuttleSync AI Analysis Engine Setup ==="
echo ""

# Check Python3
if ! command -v $PYTHON_BIN &> /dev/null; then
    echo "[ERROR] Python3 not found. Install via aaPanel > Python Project > Add Project."
    exit 1
fi

PYTHON_VER=$($PYTHON_BIN --version 2>&1)
echo "[OK] Found $PYTHON_VER"

# Create virtual environment
if [ ! -d "$VENV_DIR" ]; then
    echo "[..] Creating virtual environment..."
    $PYTHON_BIN -m venv "$VENV_DIR"
    echo "[OK] Virtual environment created at $VENV_DIR"
else
    echo "[OK] Virtual environment already exists"
fi

# Activate and install dependencies
echo "[..] Installing dependencies..."
source "$VENV_DIR/bin/activate"
pip install --upgrade pip > /dev/null 2>&1
pip install -r "$SCRIPT_DIR/requirements.txt"

echo ""
echo "=== Setup Complete ==="
echo "Python path: $VENV_DIR/bin/python"
echo "Analysis engine: $SCRIPT_DIR/analysis_engine.py"
