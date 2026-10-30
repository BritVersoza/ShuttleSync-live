#!/usr/bin/env python3
"""
ShuttleSync AI Biomechanical Analysis Engine  (v2 - accuracy overhaul)
Uses MediaPipe Pose + Hands for badminton stroke analysis.

CLI
    analysis_engine.py <image_path> <shot_type> [handedness]

    shot_type  : Smash | Serve | Cross Drop | Net Play | General Play | Auto
                 A specific type is used as a prior for auto-detection (it wins
                 close calls); "Auto" (or anything unrecognised) is pure detection.
    handedness : right | left | auto        (optional, default: auto)

Output: a single JSON object on stdout (same schema as v1, plus extra fields).

Env vars
    SHUTTLESYNC_MODEL_COMPLEXITY  2 (default, heavy) | 1 | 0
    SHUTTLESYNC_TTA               full (default, most accurate) | fast

Accuracy changes vs v1 (see ACCURACY NOTES comments throughout):
  * Test-time augmentation: pose is estimated on several views (full frame,
    person crop, contrast-enhanced, horizontally flipped) and fused with a
    robust, visibility-weighted, outlier-rejecting average. View disagreement
    lowers a joint's reliability instead of being hidden.
  * Joint angles come from MediaPipe's metric 3D world landmarks instead of a
    self-calibrated z that inflated depth for square-on poses.
  * All thresholds are body-relative (torso length / 3D shoulder width), not
    raw pixels, so scores no longer change with photo resolution.
  * The racket arm is identified (or given) and scored on its own, instead of
    taking max/min over both arms (which let the free arm score the swing).
  * "Hip rotation" is real shoulder-vs-hip separation (X-factor) in 3D, not the
    difference between the two hip flexion angles.
  * Pose similarity uses aspect-correct, visibility-weighted Procrustes
    alignment, tested against mirrored references (left-handers, back views).
  * Smooth band scoring instead of 85/55 step functions; reliability-weighted
    averaging; no free points for undetected hands or constant filler metrics.
  * Hands are re-detected on upscaled crops around each wrist and assigned to
    the correct arm; grip is measured as finger flexion in 3D.
  * Image-quality checks run on the player, not the whole frame.
  * MediaPipe's model-download message no longer corrupts the JSON on stdout,
    and a failed heavy-model download falls back to the full model.
"""
import contextlib
import json
import math
import os
import sys

os.environ.setdefault("GLOG_minloglevel", "2")
os.environ.setdefault("TF_CPP_MIN_LOG_LEVEL", "3")

import cv2
import numpy as np
import mediapipe as mp

ENGINE_VERSION = "2.0"
MODEL_COMPLEXITY = int(os.environ.get("SHUTTLESYNC_MODEL_COMPLEXITY", "2"))
# Test-time augmentation: "full" (default, most accurate) or "fast" (anchor + flip only).
TTA_MODE = os.environ.get("SHUTTLESYNC_TTA", "full").lower()

# MediaPipe Pose left/right landmark pairs (used for flip augmentation).
_POSE_LR_PAIRS = [(1, 4), (2, 5), (3, 6), (7, 8), (9, 10), (11, 12), (13, 14),
                  (15, 16), (17, 18), (19, 20), (21, 22), (23, 24), (25, 26),
                  (27, 28), (29, 30), (31, 32)]
POSE_FLIP = list(range(33))
for _a, _b in _POSE_LR_PAIRS:
    POSE_FLIP[_a], POSE_FLIP[_b] = _b, _a

KEY_JOINTS = [0, 11, 12, 13, 14, 15, 16, 23, 24, 25, 26, 27, 28]
KEY_SWAP = {0: 0, 11: 12, 12: 11, 13: 14, 14: 13, 15: 16, 16: 15,
            23: 24, 24: 23, 25: 26, 26: 25, 27: 28, 28: 27}

SIDE_IDX = {
    "right": {"shoulder": 12, "elbow": 14, "wrist": 16, "hip": 24, "knee": 26, "ankle": 28},
    "left": {"shoulder": 11, "elbow": 13, "wrist": 15, "hip": 23, "knee": 25, "ankle": 27},
}
OTHER_SIDE = {"right": "left", "left": "right"}


# --------------------------------------------------------------------------- #
# Small helpers
# --------------------------------------------------------------------------- #
class _Landmark:
    """Lightweight landmark stand-in (fused / refined joints)."""
    __slots__ = ("x", "y", "z", "visibility")

    def __init__(self, x, y, z, visibility):
        self.x = float(x)
        self.y = float(y)
        self.z = float(z)
        self.visibility = float(visibility)


def _band(value, lo, hi, tol, floor=15.0):
    """Smooth score: 100 inside [lo, hi], Gaussian fall-off (width `tol`) to `floor`.

    ACCURACY NOTE: replaces v1's hard steps (e.g. 139 deg -> 85, 141 deg -> 60),
    so a 2-degree landmark wobble can no longer swing a metric by 25 points.
    """
    if value is None:
        return None
    v = float(value)
    if lo <= v <= hi:
        return 100.0
    d = (lo - v) if v < lo else (v - hi)
    return floor + (100.0 - floor) * math.exp(-0.5 * (d / tol) ** 2)


def _mean(values):
    vals = [v for v in values if v is not None]
    return sum(vals) / len(vals) if vals else None


def _rel_weight(rel):
    """Map joint reliability (0-1) to an averaging weight."""
    if rel is None:
        return 1.0
    return float(np.clip((rel - 0.25) / 0.5, 0.1, 1.0))


def _weighted_median(values, weights):
    order = np.argsort(values)
    v = np.asarray(values)[order]
    w = np.asarray(weights)[order]
    cw = np.cumsum(w)
    return float(v[np.searchsorted(cw, cw[-1] / 2.0)])


def _clean(obj):
    """Make results JSON-safe (numpy types, NaN) and round floats."""
    if isinstance(obj, dict):
        return {str(k): _clean(v) for k, v in obj.items()}
    if isinstance(obj, (list, tuple)):
        return [_clean(v) for v in obj]
    if isinstance(obj, (np.bool_, bool)):
        return bool(obj)
    if isinstance(obj, (np.integer,)):
        return int(obj)
    if isinstance(obj, (np.floating, float)):
        f = float(obj)
        return None if (math.isnan(f) or math.isinf(f)) else round(f, 4)
    if isinstance(obj, np.ndarray):
        return _clean(obj.tolist())
    return obj


class _Scorecard:
    """Collects metric scores with weights, reliabilities and feedback."""

    def __init__(self):
        self.items = {}
        self.tips = []

    def add(self, name, score, rel=None, weight=1.0, good="", mid="", bad=""):
        if score is None:
            return
        score = float(np.clip(score, 0, 100))
        msg = good if score >= 80 else (mid if score >= 60 else bad)
        self.items[name] = {"score": score, "weight": weight, "rel": rel, "msg": msg}

    def overall(self, names=None):
        num = den = 0.0
        for n, it in self.items.items():
            if names is not None and n not in names:
                continue
            w = it["weight"] * _rel_weight(it["rel"])
            num += it["score"] * w
            den += w
        return (num / den) if den > 0 else None

    def mean_reliability(self):
        num = den = 0.0
        for it in self.items.values():
            num += (it["rel"] if it["rel"] is not None else 0.8) * it["weight"]
            den += it["weight"]
        return num / den if den > 0 else 0.0

    def feedback(self):
        # Biggest problems first (lowest weighted score), then strengths.
        ordered = sorted(self.items.values(), key=lambda it: it["score"] - 5 * it["weight"])
        msgs = [it["msg"] for it in ordered if it["msg"]]
        return " ".join(msgs + self.tips)


# --------------------------------------------------------------------------- #
# Analyzer
# --------------------------------------------------------------------------- #
class BadmintonAnalyzer:
    """Analyzes badminton strokes using MediaPipe Pose + Hands."""

    # MediaPipe Pose landmark indices
    LEFT_SHOULDER = 11
    RIGHT_SHOULDER = 12
    LEFT_ELBOW = 13
    RIGHT_ELBOW = 14
    LEFT_WRIST = 15
    RIGHT_WRIST = 16
    LEFT_HIP = 23
    RIGHT_HIP = 24
    LEFT_KNEE = 25
    RIGHT_KNEE = 26
    LEFT_ANKLE = 27
    RIGHT_ANKLE = 28
    NOSE = 0
    LEFT_PINKY = 17
    RIGHT_PINKY = 18
    LEFT_INDEX = 19
    RIGHT_INDEX = 20
    LEFT_THUMB = 21
    RIGHT_THUMB = 22

    # Reference skeleton connections for drawing
    SKELETON_CONNECTIONS = [
        (11, 12), (11, 13), (13, 15), (12, 14), (14, 16),
        (11, 23), (12, 24), (23, 24), (23, 25), (24, 26),
        (25, 27), (26, 28), (0, 11), (0, 12),
    ]

    # Reference skeleton poses (normalized x,y in 0-1 range), right-handed player.
    REFERENCE_POSES = {
        "Smash": {
            "landmarks": {
                0: (0.48, 0.08), 11: (0.38, 0.22), 12: (0.58, 0.20),
                13: (0.30, 0.18), 14: (0.68, 0.10), 15: (0.25, 0.25),
                16: (0.72, 0.03), 23: (0.42, 0.45), 24: (0.54, 0.44),
                25: (0.38, 0.65), 26: (0.58, 0.60), 27: (0.35, 0.85),
                28: (0.62, 0.78),
            },
            "ideal_angles": {
                "right_elbow": 170, "left_elbow": 130,
                "right_hip": 165, "left_hip": 170,
                "right_knee": 135, "left_knee": 145,
                "right_shoulder": 160, "left_shoulder": 100,
            },
            "description": "Reach high with racket arm extended. Elbow locked at contact. Wrist above shoulder.",
            "key_points": ["Wrist above head", "Elbow near-straight", "Hip rotation 20-60°", "Knees bent for jump"],
        },
        "Serve": {
            "landmarks": {
                0: (0.48, 0.15), 11: (0.40, 0.30), 12: (0.56, 0.30),
                13: (0.32, 0.38), 14: (0.62, 0.40), 15: (0.28, 0.48),
                16: (0.55, 0.52), 23: (0.43, 0.52), 24: (0.53, 0.52),
                25: (0.40, 0.70), 26: (0.55, 0.68), 27: (0.37, 0.88),
                28: (0.58, 0.87),
            },
            "ideal_angles": {
                "right_elbow": 120, "left_elbow": 100,
                "right_hip": 160, "left_hip": 155,
                "right_knee": 140, "left_knee": 135,
                "right_shoulder": 110, "left_shoulder": 95,
            },
            "description": "Low stance, wrist below waist for legal serve. Controlled arm position.",
            "key_points": ["Wrist below waist", "Low stance", "Shoulder-width feet", "Controlled torso"],
        },
        "Cross Drop": {
            "landmarks": {
                0: (0.48, 0.10), 11: (0.40, 0.24), 12: (0.56, 0.24),
                13: (0.34, 0.22), 14: (0.64, 0.20), 15: (0.30, 0.28),
                16: (0.70, 0.15), 23: (0.43, 0.46), 24: (0.53, 0.46),
                25: (0.40, 0.65), 26: (0.56, 0.63), 27: (0.37, 0.85),
                28: (0.59, 0.83),
            },
            "ideal_angles": {
                "right_elbow": 130, "left_elbow": 125,
                "right_hip": 168, "left_hip": 172,
                "right_knee": 145, "left_knee": 150,
                "right_shoulder": 135, "left_shoulder": 130,
            },
            "description": "Shoulders square for deception. Racket preparation height. Controlled hip position.",
            "key_points": ["Shoulders square", "Racket high", "Minimal hip rotation", "Balanced lunge"],
        },
        "Net Play": {
            "landmarks": {
                0: (0.45, 0.12), 11: (0.38, 0.26), 12: (0.54, 0.26),
                13: (0.32, 0.30), 14: (0.65, 0.22), 15: (0.28, 0.35),
                16: (0.78, 0.18), 23: (0.42, 0.48), 24: (0.52, 0.48),
                25: (0.36, 0.62), 26: (0.60, 0.58), 27: (0.32, 0.80),
                28: (0.65, 0.72),
            },
            "ideal_angles": {
                "right_elbow": 160, "left_elbow": 110,
                "right_hip": 100, "left_hip": 105,
                "right_knee": 90, "left_knee": 140,
                "right_shoulder": 150, "left_shoulder": 90,
            },
            "description": "Deep lunge, arm extended forward. Racket-side leg leading. Stable base.",
            "key_points": ["Deep lunge", "Arm extended", "Racket leg forward", "Centered torso"],
        },
        "General Play": {
            "landmarks": {
                0: (0.48, 0.10), 11: (0.40, 0.24), 12: (0.56, 0.24),
                13: (0.34, 0.32), 14: (0.62, 0.32), 15: (0.30, 0.38),
                16: (0.66, 0.38), 23: (0.43, 0.46), 24: (0.53, 0.46),
                25: (0.40, 0.64), 26: (0.56, 0.64), 27: (0.37, 0.84),
                28: (0.59, 0.84),
            },
            "ideal_angles": {
                "right_elbow": 135, "left_elbow": 130,
                "right_hip": 165, "left_hip": 165,
                "right_knee": 145, "left_knee": 145,
                "right_shoulder": 120, "left_shoulder": 115,
            },
            "description": "Athletic ready position. Feet shoulder-width apart. Knees bent.",
            "key_points": ["Shoulder-width stance", "Knees bent", "Centered balance", "Arms ready"],
        },
    }

    SIMILARITY_WEIGHTS = {
        0: 1.5, 11: 2.0, 12: 2.0, 13: 1.5, 14: 1.5, 15: 2.5, 16: 2.5,
        23: 1.5, 24: 1.5, 25: 2.0, 26: 2.0, 27: 1.0, 28: 1.0,
    }

    # Which metrics feed which sub-score.
    SUB_SCORE_MAP = {
        "footwork": {"stance", "athletic_position", "foot_spacing", "lunge_depth",
                     "lead_leg", "stance_depth", "knee_bend"},
        "posture": {"torso_control", "balance", "hip_rotation", "hip_compression",
                    "body_control"},
        "swing": {"arm_extension", "arm_reach", "arm_readiness", "elbow_form",
                  "wrist_height", "wrist_position", "grip", "arm_control",
                  "preparation", "deception"},
    }

    def __init__(self, model_complexity=MODEL_COMPLEXITY):
        self.mp_pose = mp.solutions.pose
        self.mp_hands = mp.solutions.hands
        self.mp_drawing = mp.solutions.drawing_utils
        self.model_complexity = model_complexity
        self._pose_models = {}
        self._hands_model = None
        self.engine_notes = []
        self.views_used = 0

    # ------------------------------------------------------------------ #
    # Model management
    # ------------------------------------------------------------------ #
    def close(self):
        for m in self._pose_models.values():
            with contextlib.suppress(Exception):
                m.close()
        if self._hands_model is not None:
            with contextlib.suppress(Exception):
                self._hands_model.close()
        self._pose_models = {}
        self._hands_model = None

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        self.close()

    def _pose_model(self, conf):
        key = (self.model_complexity, conf)
        if key not in self._pose_models:
            try:
                # MediaPipe prints "Downloading model to ..." on stdout the first
                # time the heavy model is used, which would break JSON parsing.
                with contextlib.redirect_stdout(sys.stderr):
                    self._pose_models[key] = self.mp_pose.Pose(
                        static_image_mode=True,
                        model_complexity=self.model_complexity,
                        enable_segmentation=False,
                        min_detection_confidence=conf,
                    )
            except Exception as e:  # e.g. heavy model download blocked
                if self.model_complexity == 2:
                    self.engine_notes.append(
                        f"Heavy pose model unavailable ({e}); fell back to the full model.")
                    self.model_complexity = 1
                    return self._pose_model(conf)
                raise
        return self._pose_models[key]

    def _hands(self):
        if self._hands_model is None:
            with contextlib.redirect_stdout(sys.stderr):
                self._hands_model = self.mp_hands.Hands(
                    static_image_mode=True, max_num_hands=2,
                    model_complexity=1, min_detection_confidence=0.3)
        return self._hands_model

    # ------------------------------------------------------------------ #
    # Image preparation & quality
    # ------------------------------------------------------------------ #
    def enhance_image(self, image):
        """Gentle, exposure-adaptive enhancement (used as one TTA view).

        ACCURACY NOTE: v1 always ran CLAHE(3.0) + a strong sharpening kernel,
        which amplifies noise/JPEG artefacts and can hurt the landmark model.
        Now the original image is always analysed too, and this enhanced view
        is only one vote in the fusion.
        """
        lab = cv2.cvtColor(image, cv2.COLOR_BGR2LAB)
        l_ch = lab[:, :, 0]
        mean_l = float(np.mean(l_ch))
        if mean_l < 90:  # lift dark (e.g. backlit) subjects
            gamma = float(np.clip(math.log(0.45) / math.log(max(mean_l, 1) / 255.0), 0.4, 1.0))
            lut = np.array([((i / 255.0) ** gamma) * 255 for i in range(256)], dtype=np.uint8)
            l_ch = cv2.LUT(l_ch, lut)
        clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
        lab[:, :, 0] = clahe.apply(l_ch)
        out = cv2.cvtColor(lab, cv2.COLOR_LAB2BGR)
        blur = cv2.GaussianBlur(out, (0, 0), 1.0)
        return cv2.addWeighted(out, 1.35, blur, -0.35, 0)

    def preprocess_image(self, image):
        """Backward-compatible alias."""
        return self.enhance_image(image)

    def check_image_quality(self, image, roi=None):
        """Quality score + warnings. Sharpness/exposure are measured on the
        player region (roi = x0, y0, x1, y1) when available.

        ACCURACY NOTE: v1 measured blur and brightness on the whole frame, so a
        sharp background hid a blurred player and a bright window hid a
        backlit one. Sharpness is also measured at a fixed scale, because
        Laplacian variance depends heavily on resolution.
        """
        h, w = image.shape[:2]
        warnings = []
        quality_score = 100

        total_pixels = h * w
        if total_pixels < 200000:
            warnings.append("Low resolution image. For best results, use photos with at least 1MP (1024x768).")
            quality_score -= 30
        elif total_pixels < 500000:
            warnings.append("Medium resolution. A higher resolution photo will improve accuracy.")
            quality_score -= 10

        aspect = max(w, h) / max(1, min(w, h))
        if aspect > 2.5:
            warnings.append("Extreme aspect ratio detected. A full-body photo works best.")
            quality_score -= 15

        region = image
        if roi is not None:
            x0, y0, x1, y1 = roi
            if (x1 - x0) > 20 and (y1 - y0) > 20:
                region = image[y0:y1, x0:x1]
                if (y1 - y0) < 250:
                    warnings.append("The player is small in the frame. Move closer or zoom in so the body is at least ~500px tall.")
                    quality_score -= 15

        gray = cv2.cvtColor(region, cv2.COLOR_BGR2GRAY)
        rh, rw = gray.shape[:2]
        scale = 512.0 / max(rh, rw)
        gray_n = cv2.resize(gray, (max(1, int(rw * scale)), max(1, int(rh * scale))),
                            interpolation=cv2.INTER_AREA if scale < 1 else cv2.INTER_CUBIC)
        laplacian_var = cv2.Laplacian(gray_n, cv2.CV_64F).var()
        if laplacian_var < 40:
            warnings.append("The player appears blurry. A sharper photo will give more accurate results.")
            quality_score -= 25
        elif laplacian_var < 110:
            warnings.append("The player is slightly soft/motion-blurred. Try a faster shutter or burst mode.")
            quality_score -= 10

        mean_brightness = float(np.mean(gray))
        if mean_brightness < 50:
            warnings.append("The player is too dark (possibly backlit). Better lighting will improve detection.")
            quality_score -= 20
        elif mean_brightness > 215:
            warnings.append("The player is overexposed. Reduce brightness for better results.")
            quality_score -= 15

        contrast = float(np.std(gray))
        if contrast < 28:
            warnings.append("Low contrast between player and background. Ensure good lighting contrast.")
            quality_score -= 15

        return max(0, quality_score), warnings

    # ------------------------------------------------------------------ #
    # Geometry
    # ------------------------------------------------------------------ #
    def calculate_angle(self, point_a, point_b, point_c):
        """Angle at point_b (degrees). 2D or 3D. Returns None if degenerate."""
        if point_a is None or point_b is None or point_c is None:
            return None
        a = np.asarray(point_a, dtype=np.float64)
        b = np.asarray(point_b, dtype=np.float64)
        c = np.asarray(point_c, dtype=np.float64)
        ba, bc = a - b, c - b
        na, nc = np.linalg.norm(ba), np.linalg.norm(bc)
        if na < 1e-9 or nc < 1e-9:
            return None
        cosine = float(np.dot(ba, bc) / (na * nc))
        return round(math.degrees(math.acos(max(-1.0, min(1.0, cosine)))), 1)

    def calculate_angle_3d(self, point_a, point_b, point_c):
        return self.calculate_angle(point_a, point_b, point_c)

    def calculate_distance(self, point_a, point_b):
        return float(np.linalg.norm(np.asarray(point_a, float) - np.asarray(point_b, float)))

    def get_landmark_coords(self, landmarks, idx, img_w, img_h):
        lm = landmarks[idx]
        return (lm.x * img_w, lm.y * img_h)

    @staticmethod
    def _torso_len_px(img_xy, W, H):
        sh = (img_xy[11, :2] + img_xy[12, :2]) / 2 * [W, H]
        hp = (img_xy[23, :2] + img_xy[24, :2]) / 2 * [W, H]
        return float(np.linalg.norm(sh - hp))

    # ------------------------------------------------------------------ #
    # Pose estimation with test-time augmentation
    # ------------------------------------------------------------------ #
    def _run_pose(self, bgr, conf=0.5):
        rgb = cv2.cvtColor(bgr, cv2.COLOR_BGR2RGB)
        with contextlib.redirect_stdout(sys.stderr):
            res = self._pose_model(conf).process(rgb)
        if res is None or res.pose_landmarks is None:
            return None
        lms = res.pose_landmarks.landmark
        img = np.array([[l.x, l.y, l.z] for l in lms], dtype=np.float64)
        vis = np.array([l.visibility for l in lms], dtype=np.float64)
        world = None
        if getattr(res, "pose_world_landmarks", None) is not None:
            world = np.array([[l.x, l.y, l.z] for l in res.pose_world_landmarks.landmark],
                             dtype=np.float64)
        return img, vis, world

    def _estimate_view(self, full_bgr, box, flip=False, enhance=False, conf=0.5, target_min=512):
        """Run pose on one view (crop / enhance / flip) and map back to full-image coords."""
        H, W = full_bgr.shape[:2]
        x0, y0, x1, y1 = box
        crop = full_bgr[y0:y1, x0:x1]
        ch, cw = crop.shape[:2]
        if ch < 16 or cw < 16:
            return None
        if min(ch, cw) < target_min:
            s = min(3.0, target_min / float(min(ch, cw)))
            crop = cv2.resize(crop, None, fx=s, fy=s, interpolation=cv2.INTER_CUBIC)
        if enhance:
            crop = self.enhance_image(crop)
        if flip:
            crop = cv2.flip(crop, 1)
        out = self._run_pose(crop, conf)
        self.views_used += 1
        if out is None:
            return None
        img, vis, world = out
        if flip:
            # Un-mirror x and swap anatomical left/right labels.
            img = img[POSE_FLIP].copy()
            img[:, 0] = 1.0 - img[:, 0]
            vis = vis[POSE_FLIP].copy()
            if world is not None:
                world = world[POSE_FLIP].copy()
                world[:, 0] = -world[:, 0]
        full = img.copy()
        full[:, 0] = (x0 + img[:, 0] * cw) / W
        full[:, 1] = (y0 + img[:, 1] * ch) / H
        full[:, 2] = img[:, 2] * cw / W   # MediaPipe z uses the x scale of its input
        return {"img": full, "vis": vis, "world": world}

    def _person_box(self, cand, W, H, pad=0.25):
        pts = cand["img"][:, :2] * [W, H]
        ok = cand["vis"] > 0.3
        if ok.sum() < 6:
            ok = np.ones(33, dtype=bool)
        xs, ys = pts[ok, 0], pts[ok, 1]
        bw, bh = xs.max() - xs.min(), ys.max() - ys.min()
        side_pad = pad * max(bw, 0.35 * bh)
        x0 = int(max(0, xs.min() - side_pad))
        x1 = int(min(W, xs.max() + side_pad))
        y0 = int(max(0, ys.min() - pad * bh - 0.15 * bh))   # headroom for a raised racket arm
        y1 = int(min(H, ys.max() + pad * bh))
        return x0, y0, x1, y1

    def _align_lr(self, cand, ref, W, H):
        """Swap a candidate's L/R labels if that matches the reference better
        (MediaPipe sometimes confuses sides in back views; averaging a swapped
        estimate with an unswapped one would collapse limbs onto the midline)."""
        idx = [11, 12, 13, 14, 15, 16, 23, 24, 25, 26, 27, 28]
        a = cand["img"][idx, :2] * [W, H]
        s = cand["img"][[POSE_FLIP[i] for i in idx], :2] * [W, H]
        r = ref["img"][idx, :2] * [W, H]
        e_same = np.linalg.norm(a - r, axis=1).mean()
        e_swap = np.linalg.norm(s - r, axis=1).mean()
        if e_swap < 0.7 * e_same:
            out = {"img": cand["img"][POSE_FLIP].copy(), "vis": cand["vis"][POSE_FLIP].copy(),
                   "world": None if cand["world"] is None else cand["world"][POSE_FLIP].copy()}
            return out
        return cand

    def _fuse(self, cands, W, H):
        """Robust fusion of TTA candidates.

        Per joint: visibility-weighted median -> reject views further than
        18% of torso length -> visibility^2-weighted mean of the rest.
        Fused visibility is scaled by view agreement, so a joint the views
        disagree on is reported as less reliable.
        """
        ref = max(cands, key=lambda c: float(np.mean(c["vis"][KEY_JOINTS])))
        cands = [ref] + [self._align_lr(c, ref, W, H) for c in cands if c is not ref]
        n = len(cands)
        P = np.stack([c["img"] for c in cands])
        V = np.stack([c["vis"] for c in cands])
        worlds = [c["world"] for c in cands]
        torso = self._torso_len_px(ref["img"], W, H)
        if torso < 1:
            torso = 0.25 * H
        gate = 0.18 * torso

        img = np.zeros((33, 3))
        vis = np.zeros(33)
        agreement = np.zeros(33)
        spread = np.zeros(33)
        world = np.zeros((33, 3)) if any(w is not None for w in worlds) else None
        for j in range(33):
            px = P[:, j, 0] * W
            py = P[:, j, 1] * H
            w = np.clip(V[:, j], 1e-3, 1.0) ** 2
            mx, my = _weighted_median(px, w), _weighted_median(py, w)
            d = np.hypot(px - mx, py - my)
            keep = d <= gate
            if not keep.any():
                keep = d <= d.min() + 1e-9
            wk = w * keep
            img[j] = (P[:, j, :] * wk[:, None]).sum(0) / wk.sum()
            agreement[j] = keep.sum() / n
            vis[j] = float(V[keep, j].mean()) * (0.6 + 0.4 * agreement[j])
            spread[j] = float(np.sqrt((wk * d ** 2).sum() / wk.sum())) / torso
            if world is not None:
                ws = [(worlds[k], wk[k]) for k in range(n) if keep[k] and worlds[k] is not None]
                if ws:
                    tw = sum(x[1] for x in ws)
                    world[j] = sum(x[0][j] * x[1] for x in ws) / tw
        return {"img": img, "vis": vis, "world": world, "agreement": agreement,
                "spread": spread, "views": n}

    def _detect_any(self, image):
        H, W = image.shape[:2]
        for conf in (0.5, 0.4):
            for enh in (False, True):
                c = self._estimate_view(image, (0, 0, W, H), enhance=enh, conf=conf)
                if c is not None:
                    return c
        return None

    def _subject_score(self, cand, W, H):
        """Prefer the biggest, most central, most visible person."""
        pts = cand["img"][KEY_JOINTS, :2] * [W, H]
        height = (pts[:, 1].max() - pts[:, 1].min()) / H
        cx = pts[:, 0].mean() / W
        centrality = 1.0 - min(1.0, abs(cx - 0.5) * 1.2)
        vis = float(np.mean(cand["vis"][KEY_JOINTS]))
        return height * (0.6 + 0.4 * centrality) * (0.4 + 0.6 * vis)

    def _find_subject(self, image, max_people=3):
        """MediaPipe Pose returns ONE person, not necessarily the player.

        ACCURACY NOTE: v1 analysed whoever MediaPipe picked (a doubles partner,
        an opponent, a spectator). We find up to `max_people` people by masking
        each detection and re-running, then keep the largest/most central one.
        """
        H, W = image.shape[:2]
        work = image.copy()
        people = []
        for _ in range(max_people):
            c = self._detect_any(work)
            if c is None or float(np.mean(c["vis"][KEY_JOINTS])) < 0.35:
                break
            people.append(c)
            x0, y0, x1, y1 = self._person_box(c, W, H, pad=0.05)
            work[y0:y1, x0:x1] = cv2.blur(work[y0:y1, x0:x1], (61, 61))
        if not people:
            return None
        best = max(people, key=lambda c: self._subject_score(c, W, H))
        if len(people) > 1:
            self.engine_notes.append(f"{len(people)} people found; analysed the most prominent one.")
        return best

    @staticmethod
    def _same_person(cand, anchor, W, H, torso):
        a = cand["img"][KEY_JOINTS, :2] * [W, H]
        b = anchor["img"][KEY_JOINTS, :2] * [W, H]
        c = anchor["img"][[POSE_FLIP[i] for i in KEY_JOINTS], :2] * [W, H]
        d = min(np.linalg.norm(a - b, axis=1).mean(), np.linalg.norm(a - c, axis=1).mean())
        return d < 0.6 * torso

    def estimate_pose(self, image):
        """Multi-view pose estimate. Returns (fused dict, person box) or (None, None)."""
        H, W = image.shape[:2]
        full = (0, 0, W, H)
        anchor = self._find_subject(image)
        if anchor is None:
            return None, None
        torso = max(1.0, self._torso_len_px(anchor["img"], W, H))

        cands = [anchor]
        box = self._person_box(anchor, W, H)
        box_area = (box[2] - box[0]) * (box[3] - box[1])
        if TTA_MODE == "fast":
            views = [(box, True, False)]
        elif box_area < 0.8 * W * H:
            views = [(box, False, False), (box, True, False), (box, False, True), (box, True, True)]
        else:
            views = [(full, True, False), (full, False, True), (full, True, True)]
        for bx, flip, enh in views:
            c = self._estimate_view(image, bx, flip=flip, enhance=enh, conf=0.4)
            if c is not None and self._same_person(c, anchor, W, H, torso):
                cands.append(c)

        fused = self._fuse(cands, W, H)
        return fused, self._person_box(fused, W, H, pad=0.1)

    def refine_arm_landmarks(self, image, pose_lm, side, model_complexity=None, torso_px=None):
        """Re-run pose on an upscaled crop of one arm (motion-blurred racket arm).

        ACCURACY NOTE: the crop contains only part of a body, so the model can
        hallucinate. A refined joint is only accepted if it is more visible,
        stays within 30% of torso length of the fused estimate, and keeps a
        plausible upper-arm/forearm length ratio. Crop z is never used
        (it is in a different depth scale).
        """
        h, w = image.shape[:2]
        joints = [12, 14, 16] if side == "right" else [11, 13, 15]
        px = [(pose_lm[j].x * w, pose_lm[j].y * h) for j in joints]
        xs = [p[0] for p in px]
        ys = [p[1] for p in px]
        span = max(1e-6, max(xs) - min(xs) + max(ys) - min(ys))
        pad = max(40, int(0.30 * span))
        x0, y0 = max(0, int(min(xs)) - pad), max(0, int(min(ys)) - pad)
        x1, y1 = min(w, int(max(xs)) + pad), min(h, int(max(ys)) + pad)
        if (x1 - x0) < 40 or (y1 - y0) < 40:
            return None

        crop = image[y0:y1, x0:x1]
        ch, cw = crop.shape[:2]
        s = min(3.0, 1280.0 / max(ch, cw))
        crop_up = cv2.resize(crop, None, fx=s, fy=s, interpolation=cv2.INTER_CUBIC)
        out = self._run_pose(crop_up, 0.5)
        if out is None:
            return None
        r_img, r_vis, _ = out
        if torso_px is None or torso_px <= 0:
            torso_px = 0.25 * h

        new = {}
        for j in joints:
            nx = (x0 + r_img[j, 0] * cw) / w
            ny = (y0 + r_img[j, 1] * ch) / h
            if not (0 <= nx <= 1 and 0 <= ny <= 1):
                continue
            if r_vis[j] <= pose_lm[j].visibility + 0.05:
                continue
            if math.hypot((nx - pose_lm[j].x) * w, (ny - pose_lm[j].y) * h) > 0.30 * torso_px:
                continue
            new[j] = (nx, ny, float(r_vis[j]))
        if not new:
            return None

        # Plausibility: upper arm / forearm length ratio after replacement.
        def pos(j):
            if j in new:
                return np.array(new[j][:2]) * [w, h]
            return np.array([pose_lm[j].x * w, pose_lm[j].y * h])
        upper = np.linalg.norm(pos(joints[1]) - pos(joints[0]))
        fore = np.linalg.norm(pos(joints[2]) - pos(joints[1]))
        if fore > 1e-6 and not (0.4 <= upper / fore <= 2.5):
            return None

        repl = {}
        for j, (nx, ny, v) in new.items():
            o = pose_lm[j]
            wo, wn = max(o.visibility, 1e-3), v
            bx = (o.x * wo + nx * wn) / (wo + wn)
            by = (o.y * wo + ny * wn) / (wo + wn)
            repl[j] = _Landmark(bx, by, o.z, max(o.visibility, v))
        return repl

    # ------------------------------------------------------------------ #
    # Main pose pipeline
    # ------------------------------------------------------------------ #
    def analyze_pose(self, image_path):
        """Detect, fuse, refine and validate the pose.

        Returns (pose_lm, img_w, img_h, hand_landmarks_list, quality_score,
        quality_warnings); extra results are stored on self.last_* attributes.
        """
        self.last_world = None
        self.last_refined = set()
        self.last_box = None
        self.last_image = None
        self.last_agreement = None

        image = cv2.imread(image_path, cv2.IMREAD_COLOR)   # applies EXIF orientation
        if image is None:
            raise ValueError(f"Could not read image: {image_path}")
        h0, w0 = image.shape[:2]
        if max(h0, w0) > 2560:   # plenty for pose/hands, keeps runtime sane
            s = 2560.0 / max(h0, w0)
            image = cv2.resize(image, None, fx=s, fy=s, interpolation=cv2.INTER_AREA)
        img_h, img_w = image.shape[:2]
        self.last_image = image

        fused, box = self.estimate_pose(image)
        quality_score, quality_warnings = self.check_image_quality(image, roi=box)
        if fused is None:
            # v1 bug: a generic quality warning could become the headline feedback.
            return None, img_w, img_h, [], quality_score, [
                "No person detected. Ensure your full body is visible in the frame from head to toe."
            ] + quality_warnings

        pose_lm = [_Landmark(*fused["img"][i], fused["vis"][i]) for i in range(33)]
        self.last_world = fused["world"]
        self.last_box = box
        self.last_agreement = fused["agreement"]

        torso_px = self._torso_len_px(fused["img"], img_w, img_h)
        for side in ("right", "left"):
            repl = self.refine_arm_landmarks(image, pose_lm, side, torso_px=torso_px)
            if repl:
                for j, lm in repl.items():
                    pose_lm[j] = lm
                    self.last_refined.add(j)

        # ---- FULL BODY VALIDATION ----
        LOWER_BODY = [23, 24, 25, 26, 27, 28]
        lower_vis = [pose_lm[i].visibility for i in LOWER_BODY]
        all_vis = [pose_lm[i].visibility for i in KEY_JOINTS]
        avg_visibility = sum(all_vis) / len(all_vis)
        avg_lower_vis = sum(lower_vis) / len(lower_vis)
        well_visible = sum(1 for v in all_vis if v > 0.5)
        lower_visible = sum(1 for v in lower_vis if v > 0.5)
        y_coords = [pose_lm[i].y for i in KEY_JOINTS]
        body_span = max(y_coords) - min(y_coords)

        if lower_visible == 0:
            return None, img_w, img_h, [], quality_score, [
                "Full body not detected. Only upper body / face was found. "
                "Please stand back so your entire body (head to toe) is visible in the frame."
            ]
        if avg_lower_vis < 0.3:
            return None, img_w, img_h, [], quality_score, [
                "Lower body (hips, knees, feet) is not clearly visible. "
                "Ensure your full body from head to toe is in the frame with good lighting."
            ]
        if body_span < 0.40:
            quality_warnings.append(
                "Only a portion of your body is visible. "
                "Step back so your full body (head to toe) fills most of the frame.")
            quality_score = max(0, quality_score - 25)
        if well_visible < 8:
            quality_warnings.append(
                f"Only {well_visible}/13 key body points clearly visible. "
                "Ensure good lighting and that your entire body is in view.")
            quality_score = max(0, quality_score - 15)
        if avg_visibility < 0.4:
            quality_warnings.append("Body detection confidence is low. Ensure the full body is clearly visible.")
            quality_score = max(0, quality_score - 20)
        elif avg_visibility < 0.6:
            quality_warnings.append("Some body parts may be partially occluded.")
            quality_score = max(0, quality_score - 10)

        return pose_lm, img_w, img_h, [], quality_score, quality_warnings

    # ------------------------------------------------------------------ #
    # Racket side
    # ------------------------------------------------------------------ #
    def determine_racket_side(self, pose_lm, img_w, img_h, handedness="auto"):
        """Pick the racket arm. Returns (side, confidence 0-1, source).

        ACCURACY NOTE: v1 scored max()/min() over BOTH arms, so a straight
        non-racket arm could earn "elbow lock is solid". Auto mode uses a
        right-handed prior (~85-90% of players) and only switches with clear
        evidence; in a smash preparation the free arm is often the higher one,
        so passing handedness explicitly is the most accurate option.
        """
        if handedness in ("right", "left"):
            return handedness, 1.0, "user"
        H, W = img_h, img_w
        mid_sh_y = (pose_lm[11].y + pose_lm[12].y) / 2 * H
        mid_hp = np.array([(pose_lm[23].x + pose_lm[24].x) / 2 * W, (pose_lm[23].y + pose_lm[24].y) / 2 * H])
        torso = max(1.0, abs(mid_sh_y - mid_hp[1]))
        ev = {}
        for side, ix in SIDE_IDX.items():
            wr = np.array([pose_lm[ix["wrist"]].x * W, pose_lm[ix["wrist"]].y * H])
            height = float(np.clip((mid_sh_y - wr[1]) / torso, -1.5, 2.0))
            away = float(np.clip(np.linalg.norm(wr - mid_hp) / torso, 0, 2.5))
            v = min(pose_lm[ix["shoulder"]].visibility, pose_lm[ix["elbow"]].visibility,
                    pose_lm[ix["wrist"]].visibility)
            ev[side] = v * (0.6 * height + 0.4 * away)
        diff = ev["right"] - ev["left"] + 0.35
        side = "right" if diff >= 0 else "left"
        conf = 1.0 / (1.0 + math.exp(-4.0 * abs(diff)))
        return side, round(conf, 3), "auto"

    # ------------------------------------------------------------------ #
    # Body metrics
    # ------------------------------------------------------------------ #
    ANGLE_DEFS = {
        "right_shoulder_angle": (24, 12, 14), "left_shoulder_angle": (23, 11, 13),
        "right_elbow_angle": (12, 14, 16), "left_elbow_angle": (11, 13, 15),
        "right_hip_angle": (12, 24, 26), "left_hip_angle": (11, 23, 25),
        "right_knee_angle": (24, 26, 28), "left_knee_angle": (23, 25, 27),
    }

    def extract_body_metrics(self, pose_lm, img_w, img_h, world=None, refined=(), racket_side="right"):
        """Joint angles (3D) + body-relative measurements.

        ACCURACY NOTE: angles use MediaPipe's metric world landmarks. v1 scaled
        image z so the torso's depth range always equalled its width; for a
        player square to the camera that blew up tiny z noise into large fake
        depth. Joints replaced by arm refinement use image-space 3D with z in
        MediaPipe's documented x-scale instead, since world landmarks were not
        refined.
        """
        W, H = img_w, img_h
        refined = set(refined or ())

        def p2(i):
            return np.array([pose_lm[i].x * W, pose_lm[i].y * H])

        def p3(i):
            return np.array([pose_lm[i].x * W, pose_lm[i].y * H, pose_lm[i].z * W])

        def pw(i):
            return None if world is None else world[i]

        def vis(*idx):
            return round(float(min(pose_lm[i].visibility for i in idx)), 3)

        angles, angles_2d, foreshortening, reliability, source = {}, {}, {}, {}, {}
        for name, (a, b, c) in self.ANGLE_DEFS.items():
            a2 = self.calculate_angle(p2(a), p2(b), p2(c))
            ai = self.calculate_angle(p3(a), p3(b), p3(c))
            aw = self.calculate_angle(pw(a), pw(b), pw(c)) if world is not None else None
            use_img = aw is None or any(j in refined for j in (a, b, c))
            prim = ai if use_img else aw
            if prim is None:
                prim = a2
            rel = vis(a, b, c)
            if not use_img and ai is not None and abs(ai - aw) > 45:
                rel = round(rel * 0.85, 3)   # two 3D estimates disagree
            angles[name] = prim
            angles_2d[name] = a2
            foreshortening[name] = None if (prim is None or a2 is None) else round(abs(prim - a2), 1)
            reliability[name] = rel
            source[name] = "image_3d" if use_img else "world_3d"

        # --- body-relative measurements ---
        mid_sh = (p2(11) + p2(12)) / 2
        mid_hp = (p2(23) + p2(24)) / 2
        torso_px = float(np.linalg.norm(mid_sh - mid_hp))
        if torso_px < 1:
            torso_px = 0.25 * H

        rs, os_ = SIDE_IDX[racket_side], SIDE_IDX[OTHER_SIDE[racket_side]]
        arm_refined = any(j in refined for j in (rs["shoulder"], rs["elbow"], rs["wrist"]))
        P = p3 if (world is None or arm_refined) else pw

        def reach_ratio(ix):
            sh, el, wr = P(ix["shoulder"]), P(ix["elbow"]), P(ix["wrist"])
            L = np.linalg.norm(el - sh) + np.linalg.norm(wr - el)
            return float(np.linalg.norm(wr - sh) / L) if L > 1e-9 else None

        wr_y = p2(rs["wrist"])[1]
        el_y = p2(rs["elbow"])[1]
        sh_y = p2(rs["shoulder"])[1]
        waist_y = mid_hp[1] - 0.2 * torso_px

        # Stance width relative to 3D shoulder width (view-independent);
        # feet distance measured horizontally (x/z) so a raised foot doesn't count.
        if world is not None:
            sw = float(np.linalg.norm(world[11] - world[12]))
            fd = float(np.linalg.norm((world[27] - world[28])[[0, 2]]))
            stance_ratio = fd / sw if sw > 1e-6 else None
        else:
            sw2 = max(float(np.linalg.norm(p2(11) - p2(12))), 0.6 * torso_px)
            stance_ratio = float(np.linalg.norm(p2(27) - p2(28))) / sw2

        # Torso lean from vertical
        if world is not None:
            t = (world[11] + world[12]) / 2 - (world[23] + world[24]) / 2
            nt = np.linalg.norm(t)
            lean = math.degrees(math.acos(float(np.clip(-t[1] / nt, -1, 1)))) if nt > 1e-9 else None
        else:
            d = mid_sh - mid_hp
            lean = math.degrees(math.atan2(abs(d[0]), max(1e-6, -d[1])))

        # Shoulder-hip separation (X-factor) and shoulder turn, horizontal plane.
        trunk_rotation = shoulder_turn = None
        if world is not None:
            s = (world[12] - world[11])[[0, 2]]
            hvec = (world[24] - world[23])[[0, 2]]
            ns, nh = np.linalg.norm(s), np.linalg.norm(hvec)
            if ns > 1e-6 and nh > 1e-6:
                ang = math.degrees(math.acos(float(np.clip(np.dot(s, hvec) / (ns * nh), -1, 1))))
                trunk_rotation = min(ang, 180 - ang)
                shoulder_turn = math.degrees(math.atan2(abs(s[1]), abs(s[0])))

        knees = [angles["right_knee_angle"], angles["left_knee_angle"]]
        hips = [angles["right_hip_angle"], angles["left_hip_angle"]]
        rk = angles[f"{racket_side}_knee_angle"]
        ok = angles[f"{OTHER_SIDE[racket_side]}_knee_angle"]
        mid_ank = (p2(27) + p2(28)) / 2

        m = {
            "racket_side": racket_side,
            "racket_elbow_angle": angles[f"{racket_side}_elbow_angle"],
            "racket_shoulder_angle": angles[f"{racket_side}_shoulder_angle"],
            "racket_reach_ratio": reach_ratio(rs),
            "racket_wrist_height": (mid_sh[1] - wr_y) / torso_px,        # + = above shoulders
            "racket_elbow_height": (sh_y - el_y) / torso_px,             # + = elbow above shoulder
            "racket_wrist_below_waist": (wr_y - waist_y) / torso_px,     # + = below waist
            "racket_wrist_above_head": bool(wr_y < p2(0)[1]),
            "off_wrist_height": (mid_sh[1] - p2(os_["wrist"])[1]) / torso_px,
            "front_knee_angle": min(k for k in knees if k is not None) if any(k is not None for k in knees) else None,
            "mean_knee_angle": _mean(knees),
            "min_hip_angle": min(h for h in hips if h is not None) if any(h is not None for h in hips) else None,
            "racket_leg_leads": (rk is not None and ok is not None and rk < ok - 10),
            "stance_ratio": stance_ratio,
            "torso_lean_deg": lean,
            "trunk_rotation_deg": trunk_rotation,
            "shoulder_turn_deg": shoulder_turn,
            "com_offset": abs(mid_hp[0] - mid_ank[0]) / torso_px,
            "torso_length_px": torso_px,
        }
        m_rel = {
            "racket_arm": vis(rs["shoulder"], rs["elbow"], rs["wrist"]),
            "legs": vis(23, 24, 25, 26, 27, 28),
            "torso": vis(11, 12, 23, 24),
            "feet": vis(27, 28),
            "knees": vis(25, 26),
        }

        body = dict(angles)
        body.update({
            "angles_2d": angles_2d,
            "foreshortening": foreshortening,
            "reliability": reliability,
            "angle_source": source,
            "measurements": m,
            "measurement_reliability": m_rel,
            # v1-compatible fields
            "right_wrist_above_shoulder": bool(p2(16)[1] < mid_sh[1]),
            "left_wrist_above_shoulder": bool(p2(15)[1] < mid_sh[1]),
            "shoulder_width": round(float(np.linalg.norm(p2(11) - p2(12))), 1),
            "stance_width": round(float(np.linalg.norm(p2(27) - p2(28))), 1),
        })
        return body

    # ------------------------------------------------------------------ #
    # Hands
    # ------------------------------------------------------------------ #
    def _run_hands(self, bgr):
        rgb = cv2.cvtColor(bgr, cv2.COLOR_BGR2RGB)
        with contextlib.redirect_stdout(sys.stderr):
            res = self._hands().process(rgb)
        out = []
        if res is None or not res.multi_hand_landmarks:
            return out
        worlds = getattr(res, "multi_hand_world_landmarks", None) or []
        scores = res.multi_handedness or []
        for k, hl in enumerate(res.multi_hand_landmarks):
            img = np.array([[l.x, l.y, l.z] for l in hl.landmark])
            wld = np.array([[l.x, l.y, l.z] for l in worlds[k].landmark]) if k < len(worlds) else None
            sc = scores[k].classification[0].score if k < len(scores) else 0.5
            out.append((img, wld, float(sc)))
        return out

    def detect_hands(self, image, pose_lm):
        """Find each hand on an upscaled crop around its wrist, assign it to the
        correct arm; fall back to full-frame detection.

        ACCURACY NOTE: in a full-body photo a hand is ~30-60 px, below what the
        hand model needs; v1 also used hands[0] regardless of which arm it was.
        """
        H, W = image.shape[:2]
        found = {}
        for side, ix in SIDE_IDX.items():
            wr = np.array([pose_lm[ix["wrist"]].x * W, pose_lm[ix["wrist"]].y * H])
            el = np.array([pose_lm[ix["elbow"]].x * W, pose_lm[ix["elbow"]].y * H])
            if pose_lm[ix["wrist"]].visibility < 0.3:
                continue
            fore = float(np.linalg.norm(wr - el))
            if fore < 8:
                continue
            c = wr + (wr - el) / fore * 0.35 * fore
            half = max(32.0, 0.8 * fore)
            x0, y0 = int(max(0, c[0] - half)), int(max(0, c[1] - half))
            x1, y1 = int(min(W, c[0] + half)), int(min(H, c[1] + half))
            if x1 - x0 < 16 or y1 - y0 < 16:
                continue
            crop = image[y0:y1, x0:x1]
            ch, cw = crop.shape[:2]
            s = max(1.0, 384.0 / min(ch, cw))
            crop = cv2.resize(crop, None, fx=s, fy=s, interpolation=cv2.INTER_CUBIC)
            best = None
            for img, wld, sc in self._run_hands(crop):
                full = img.copy()
                full[:, 0] = (x0 + img[:, 0] * cw) / W
                full[:, 1] = (y0 + img[:, 1] * ch) / H
                d = math.hypot(full[0, 0] * W - wr[0], full[0, 1] * H - wr[1])
                if d <= 0.6 * fore and (best is None or d < best[0]):
                    best = (d, full, wld, sc)
            if best is not None:
                found[side] = {"side": side, "img": best[1], "world": best[2],
                               "score": best[3], "source": "wrist_crop"}

        if len(found) < 2:
            for img, wld, sc in self._run_hands(image):
                hw = img[0, :2] * [W, H]
                dists = {}
                for side, ix in SIDE_IDX.items():
                    wr = np.array([pose_lm[ix["wrist"]].x * W, pose_lm[ix["wrist"]].y * H])
                    el = np.array([pose_lm[ix["elbow"]].x * W, pose_lm[ix["elbow"]].y * H])
                    fore = max(8.0, float(np.linalg.norm(wr - el)))
                    dists[side] = (float(np.linalg.norm(hw - wr)) / fore)
                side = min(dists, key=dists.get)
                if dists[side] <= 0.6 and side not in found:
                    found[side] = {"side": side, "img": img, "world": wld,
                                   "score": sc, "source": "full_frame"}
        return list(found.values())

    def extract_hand_metrics(self, hands_found, img_w, img_h, racket_side="right"):
        """Hand metrics; grip closure = finger flexion in 3D (scale-free)."""
        if not hands_found:
            return {"hands_detected": 0, "hands": [], "racket_hand": None}

        FINGERS = [(5, 6, 7), (9, 10, 11), (13, 14, 15), (17, 18, 19)]
        hands_data = []
        for hnd in hands_found:
            img = hnd["img"]

            def c2(i):
                return (img[i, 0] * img_w, img[i, 1] * img_h)

            P = hnd["world"] if hnd["world"] is not None else img * [img_w, img_h, img_w]
            flex = []
            for mcp, pip, dip in FINGERS:
                a1 = self.calculate_angle(P[0], P[mcp], P[pip])
                a2 = self.calculate_angle(P[mcp], P[pip], P[dip])
                if a1 is not None and a2 is not None:
                    flex.append((180 - a1) + (180 - a2))
            palm = self.calculate_distance(c2(0), c2(9))
            spread = self.calculate_distance(c2(4), c2(8))
            hands_data.append({
                "side": hnd["side"],
                "is_racket_hand": hnd["side"] == racket_side,
                "source": hnd["source"],
                "confidence": round(hnd["score"], 3),
                "grip_closure_deg": round(float(np.mean(flex)), 1) if flex else None,
                "finger_spread_ratio": round(spread / palm, 3) if palm > 1e-6 else None,
                # v1-compatible (pixel) fields
                "grip_reach": round(self.calculate_distance(c2(0), c2(12)), 2),
                "finger_spread": round(spread, 2),
                "palm_distance": round(palm, 2),
                "index_curl": round(self.calculate_distance(c2(8), c2(5)), 2),
                "middle_curl": round(self.calculate_distance(c2(12), c2(9)), 2),
            })
        hands_data.sort(key=lambda d: not d["is_racket_hand"])
        racket = next((d for d in hands_data if d["is_racket_hand"]), None)
        return {"hands_detected": len(hands_data), "hands": hands_data, "racket_hand": racket}

    # ------------------------------------------------------------------ #
    # Similarity to reference poses
    # ------------------------------------------------------------------ #
    def normalize_landmarks(self, pose_lm, img_w, img_h):
        """Key joints in PIXEL units (aspect-correct).

        ACCURACY NOTE: v1 stretched x and y independently to a 0-1 box, which
        distorts the body shape (a wide lunge got squashed to look narrow).
        """
        return {i: (pose_lm[i].x * img_w, pose_lm[i].y * img_h) for i in KEY_JOINTS}

    def _ref_variant(self, ref_lm, flip_x=False, swap=False):
        out = {}
        for idx, (x, y) in ref_lm.items():
            out[KEY_SWAP[idx] if swap else idx] = ((1.0 - x) if flip_x else x, y)
        return out

    def calculate_similarity(self, user_norm, reference_landmarks, user_vis=None):
        """Weighted Procrustes (translation + uniform scale) similarity.

        Returns (similarity 0-100, per-joint errors, reference aligned onto the
        user in pixel coords).
        """
        idxs = [i for i in KEY_JOINTS if i in user_norm and i in reference_landmarks]
        U = np.array([user_norm[i] for i in idxs], dtype=np.float64)
        R = np.array([reference_landmarks[i] for i in idxs], dtype=np.float64)
        w = np.array([self.SIMILARITY_WEIGHTS.get(i, 1.0) *
                      max(0.05, (user_vis or {}).get(i, 1.0)) for i in idxs])
        w = w / w.sum()
        mu_u, mu_r = (U * w[:, None]).sum(0), (R * w[:, None]).sum(0)
        Uc, Rc = U - mu_u, R - mu_r
        su = math.sqrt(float((w * (Uc ** 2).sum(1)).sum())) or 1.0
        sr = math.sqrt(float((w * (Rc ** 2).sum(1)).sum())) or 1.0
        d = np.linalg.norm(Uc / su - Rc / sr, axis=1)
        err = math.sqrt(float((w * d ** 2).sum()))
        # sigma calibrated so a real body near a reference scores ~70, clearly
        # different references ~25-50 (hand-drawn refs are stylised).
        similarity = int(round(100 * math.exp(-0.5 * (err / 0.30) ** 2)))
        joint_errors = {i: round(float(e), 3) for i, e in zip(idxs, d)}
        aligned = {i: tuple(mu_u + (np.array(reference_landmarks[i]) - mu_r) / sr * su)
                   for i in reference_landmarks}
        return similarity, joint_errors, aligned

    def best_similarity(self, user_norm, shot_type, user_vis=None, swap_options=(False, True)):
        """Best match over mirrored reference variants (camera side / handedness)."""
        ref = self.REFERENCE_POSES[shot_type]["landmarks"]
        best = None
        for swap in swap_options:
            for flip in (False, True):
                sim, errs, aligned = self.calculate_similarity(
                    user_norm, self._ref_variant(ref, flip, swap), user_vis)
                if best is None or sim > best["similarity"]:
                    best = {"similarity": sim, "joint_errors": errs, "aligned": aligned,
                            "flip_x": flip, "swap": swap}
        return best

    def extract_detected_skeleton(self, pose_lm, img_w, img_h):
        landmarks = {}
        for idx in KEY_JOINTS:
            lm = pose_lm[idx]
            landmarks[str(idx)] = {"x": round(lm.x, 4), "y": round(lm.y, 4),
                                   "visibility": round(lm.visibility, 3)}
        return {"landmarks": landmarks, "connections": [[a, b] for a, b in self.SKELETON_CONNECTIONS]}

    def get_reference_skeleton(self, shot_type, flip_x=False, swap=False, aligned=None,
                               img_w=None, img_h=None):
        """Reference skeleton, oriented like the player; optionally also the
        reference fitted onto the player's position/scale in the photo."""
        ref = self.REFERENCE_POSES.get(shot_type, self.REFERENCE_POSES["General Play"])
        pts = self._ref_variant(ref["landmarks"], flip_x, swap)
        ideal = dict(ref["ideal_angles"])
        if swap:
            ideal = {(k.replace("right", "@").replace("left", "right").replace("@", "left")): v
                     for k, v in ideal.items()}
        out = {
            "landmarks": {str(i): {"x": round(x, 4), "y": round(y, 4)} for i, (x, y) in pts.items()},
            "connections": [[a, b] for a, b in self.SKELETON_CONNECTIONS],
            "description": ref["description"],
            "key_points": ref["key_points"],
            "ideal_angles": ideal,
            "orientation": {"mirrored": bool(flip_x), "left_handed": bool(swap)},
        }
        if aligned is not None and img_w and img_h:
            out["aligned_landmarks"] = {
                str(i): {"x": round(float(p[0]) / img_w, 4), "y": round(float(p[1]) / img_h, 4)}
                for i, p in aligned.items()}
        return out

    # ------------------------------------------------------------------ #
    # Shot-type detection
    # ------------------------------------------------------------------ #
    def _shot_features(self, body):
        m = body["measurements"]
        wh, reach = m["racket_wrist_height"], m["racket_reach_ratio"]
        elb, eh = m["racket_elbow_angle"], m["racket_elbow_height"]
        knee, stance = m["front_knee_angle"], m["stance_ratio"]
        lean, below = m["torso_lean_deg"], m["racket_wrist_below_waist"]
        hipmin = m["min_hip_angle"]
        f = 5.0
        return {
            "Smash": _mean([_band(wh, 0.6, 3.0, 0.3, f), _band(reach, 0.88, 1.0, 0.1, f),
                            _band(elb, 150, 180, 20, f), _band(eh, 0.3, 2.0, 0.3, f)]),
            "Cross Drop": _mean([_band(wh, 0.2, 1.2, 0.3, f), _band(elb, 105, 155, 15, f),
                                 _band(eh, -0.1, 0.7, 0.25, f), _band(reach, 0.65, 0.92, 0.1, f)]),
            "Serve": _mean([_band(below, 0.0, 1.5, 0.15, f), _band(lean, 0, 20, 10, f),
                            _band(knee, 115, 165, 15, f), _band(stance, 0.7, 1.6, 0.3, f),
                            _band(elb, 80, 160, 25, f)]),
            "Net Play": _mean([_band(knee, 70, 120, 15, f), _band(stance, 1.5, 3.5, 0.4, f),
                               _band(hipmin, 70, 130, 15, f), _band(reach, 0.8, 1.0, 0.12, f),
                               _band(wh, -0.6, 0.8, 0.4, f)]),
            "General Play": _mean([_band(knee, 125, 165, 12, f), _band(stance, 0.9, 1.8, 0.3, f),
                                   _band(lean, 0, 20, 10, f), _band(wh, -0.8, 0.5, 0.3, f),
                                   _band(elb, 60, 140, 25, f)]),
        }

    def auto_detect_shot_type(self, body, hands, similarities=None, requested=None):
        """Returns (detected_type, confidence 0-100, per-type scores 0-100).

        ACCURACY NOTE: v1 summed hand-tuned points on pixel distances (e.g.
        "arm_ext > 160" px), so results depended on photo resolution. Now: body-
        relative feature bands (75%) + Procrustes similarity (25%), a softmax
        confidence, and the user's selected shot as a small prior.
        """
        feats = self._shot_features(body)
        scores = {}
        for t in self.REFERENCE_POSES:
            fs = feats.get(t) or 0.0
            sim = (similarities or {}).get(t)
            scores[t] = 0.75 * fs + 0.25 * sim if sim is not None else fs
        ranked_scores = dict(scores)
        if requested in scores:
            ranked_scores[requested] += 8.0
        best = max(ranked_scores, key=ranked_scores.get)
        if scores[best] < 30:
            best = "General Play"
        vals = np.array(list(ranked_scores.values())) / 6.0
        probs = np.exp(vals - vals.max())
        probs /= probs.sum()
        prob = dict(zip(ranked_scores.keys(), probs))
        confidence = int(round(100 * prob[best]))
        return best, confidence, {k: int(round(v)) for k, v in scores.items()}

    # ------------------------------------------------------------------ #
    # Scoring
    # ------------------------------------------------------------------ #
    def score_shot(self, shot_type, body, hands):
        m = body["measurements"]
        r = body["measurement_reliability"]
        rel = body["reliability"]
        rs = m["racket_side"]
        arm_rel = r["racket_arm"]
        sc = _Scorecard()
        rhand = (hands or {}).get("racket_hand")
        closure = rhand["grip_closure_deg"] if rhand and rhand.get("confidence", 0) >= 0.5 else None

        def rel_of(*names):
            vals = [rel[n] for n in names if rel.get(n) is not None]
            return min(vals) if vals else None

        knee_rel = rel_of("right_knee_angle", "left_knee_angle")
        hip_rel = rel_of("right_hip_angle", "left_hip_angle")

        if shot_type == "Smash":
            sc.add("arm_extension", _band(m["racket_shoulder_angle"], 150, 180, 18),
                   rel.get(f"{rs}_shoulder_angle"), 1.2,
                   "Excellent arm extension at contact.",
                   "Good arm reach, try extending fully above your head.",
                   "Your racket arm is not reaching high enough. Lift the elbow and reach up for maximum height.")
            sc.add("elbow_form", _band(m["racket_elbow_angle"], 155, 180, 18),
                   rel.get(f"{rs}_elbow_angle"), 1.2,
                   "Elbow lock is solid at contact point.",
                   "Slightly bent elbow. Try snapping your arm straight at impact.",
                   "Elbow is too bent at contact, so you're losing significant power.")
            sc.add("wrist_height", _band(m["racket_wrist_height"], 0.6, 3.0, 0.35),
                   arm_rel, 1.2,
                   "Good wrist height: shuttle contact is well above your head.",
                   "Contact is a little low. Reach higher before snapping down.",
                   "Contact point is too low. Reach higher and snap down on the shuttle.")
            sc.add("hip_rotation", _band(m["trunk_rotation_deg"], 20, 60, 15),
                   rel_of("right_hip_angle", "left_hip_angle", "right_shoulder_angle", "left_shoulder_angle"), 1.0,
                   "Good shoulder-hip separation generating kinetic chain power.",
                   "Some trunk rotation. Turn the hips and shoulders more to load the swing.",
                   "Rotate your hips and trunk more during the jump for explosive power.")
            sc.add("knee_bend", _band(m["front_knee_angle"], 110, 145, 15), knee_rel, 0.8,
                   "Good knee bend for jump preparation.",
                   "Slightly more knee bend will help your jump.",
                   "Bend your knees more before the jump to generate upward force.")
            sc.add("grip", _band(closure, 110, 170, 30), rhand and rhand["confidence"], 0.5,
                   "Firm grip detected: good for smash power transfer.",
                   "Grip looks a little loose. Squeeze firmly at contact.",
                   "Grip looks loose at contact. Tighten your fingers as you strike for power.")

        elif shot_type == "Serve":
            sc.add("wrist_position", _band(m["racket_wrist_below_waist"], 0.0, 1.5, 0.15), arm_rel, 1.3,
                   "Correct wrist position below waist level for a legal serve.",
                   "Wrist is close to waist height. Keep contact clearly below the waist.",
                   "Wrist is too high. Keep the racket head below waist level.")
            sc.add("stance_depth", _band(m["front_knee_angle"], 115, 150, 15), knee_rel, 1.0,
                   "Good low, controlled stance for the serve.",
                   "Reasonable knee bend. A little lower improves serve consistency.",
                   "Your stance is too upright. Bend your knees for better control.")
            sc.add("foot_spacing", _band(m["stance_ratio"], 0.8, 1.5, 0.3), r["feet"], 0.9,
                   "Good foot positioning: about shoulder-width apart for balance.",
                   "Foot spacing is slightly off. Aim for about shoulder-width apart.",
                   "Adjust your foot spacing to about shoulder-width apart.")
            sc.add("torso_control", _band(m["torso_lean_deg"], 0, 20, 10), r["torso"], 1.0,
                   "Controlled torso position with minimal unnecessary movement.",
                   "Slight extra lean. Stay a bit more centred.",
                   "Your torso is leaning too much. Stay more centered.")
            sc.add("arm_control", _band(m["racket_elbow_angle"], 90, 150, 20),
                   rel.get(f"{rs}_elbow_angle"), 1.0,
                   "Racket arm is nicely controlled for an accurate serve trajectory.",
                   "Keep your racket arm a little more relaxed and bent for control.",
                   "Racket arm is too straight or too cramped. Keep a relaxed, bent arm for control.")
            sc.add("grip", _band(closure, 60, 130, 30), rhand and rhand["confidence"], 0.5,
                   "Relaxed grip allows for better serve touch and spin.",
                   "Grip is slightly tense. Relax your fingers for touch.",
                   "Tight grip detected. Relax your fingers for a softer serve.")

        elif shot_type == "Cross Drop":
            sc.add("deception", _band(m["racket_elbow_height"], -0.05, 0.8, 0.2), arm_rel, 1.2,
                   "Racket arm is cocked like a smash, so the opponent can't read the drop.",
                   "Preparation is close to your smash. Lift the elbow a little more to disguise it.",
                   "Your preparation differs from your smash. Lift the elbow to shoulder height to hide the drop.")
            sc.add("preparation", _band(m["racket_wrist_height"], 0.3, 2.0, 0.3), arm_rel, 1.0,
                   "Good racket preparation height for drop shot deception.",
                   "Racket could be prepared a little higher.",
                   "Raise your racket earlier to sell the smash before dropping.")
            body_ctrl = _band(m["trunk_rotation_deg"], 0, 30, 15)
            if body_ctrl is None:
                body_ctrl = _band(m["torso_lean_deg"], 0, 20, 10)
            sc.add("body_control", body_ctrl, hip_rel, 1.0,
                   "Stable hips maintain the deception throughout the stroke.",
                   "Hips are starting to open. Keep them quieter until contact.",
                   "Hip rotation reveals your intent. Keep hips stable until contact.")
            sc.add("stance", _band(m["front_knee_angle"], 120, 160, 15), knee_rel, 0.8,
                   "Balanced stance for precise shuttle placement.",
                   "Stance is okay. Stay balanced for precise placement.",
                   "Get into a balanced, slightly bent stance for precise placement.")
            sc.add("arm_control", _band(m["racket_elbow_angle"], 110, 160, 18),
                   rel.get(f"{rs}_elbow_angle"), 1.0,
                   "Good elbow angle for a controlled cross-court drop.",
                   "Adjust your arm angle slightly for more finesse.",
                   "Adjust your arm angle for more finesse on the drop shot.")
            sc.add("grip", _band(closure, 70, 140, 30), rhand and rhand["confidence"], 0.5,
                   "Ideal finger tension for a deceptive cross drop.",
                   "Finger tension is slightly off for a soft drop.",
                   "Relax your fingers at contact for a softer, more deceptive drop.")

        elif shot_type == "Net Play":
            sc.add("lunge_depth", _band(m["front_knee_angle"], 80, 110, 15), knee_rel, 1.2,
                   "Excellent deep lunge that maximizes court reach for net play.",
                   "Good lunge depth. Going deeper gives you more reach at the net.",
                   "Shallow lunge. Bend your front knee more for better net coverage.")
            sc.add("hip_compression", _band(m["min_hip_angle"], 85, 125, 15), hip_rel, 1.0,
                   "Great hip compression: stable base for net kill or spin.",
                   "Lower your hips a little more in the lunge.",
                   "Lower your hips in the lunge for a more stable net position.")
            sc.add("arm_reach", _band(m["racket_reach_ratio"], 0.85, 1.0, 0.12), arm_rel, 1.0,
                   "Full arm extension for maximum net reach.",
                   "Good reach. Extend your arm further forward at the net.",
                   "Arm is too close to body. Reach forward to take the shuttle early.")
            sc.add("balance", _band(m["torso_lean_deg"], 0, 25, 10), r["torso"], 1.0,
                   "Excellent balance with a centered torso during the net lunge.",
                   "Slight extra lean. Engage your core to stay centred.",
                   "You're leaning too much. Engage your core for better balance.")
            sc.add("stance", _band(m["stance_ratio"], 1.6, 3.2, 0.4), r["feet"], 0.8,
                   "Wide, stable base in the lunge.",
                   "Widen your lunge slightly for a more stable base.",
                   "Your base is too narrow for a net lunge. Step out further.")
            lunge = m["front_knee_angle"] is not None and m["front_knee_angle"] < 135
            if lunge:
                sc.add("lead_leg", 95.0 if m["racket_leg_leads"] else 45.0, knee_rel, 0.8,
                       "Racket-side leg leads the lunge, as it should.", "",
                       "Lead the lunge with your racket-side leg toward the net.")
            sc.add("grip", _band(closure, 50, 120, 30), rhand and rhand["confidence"], 0.5,
                   "Relaxed grip allows for quick net kills and touch shots.",
                   "Grip is slightly tight. Relax for better net touch.",
                   "Fingers are too tight. Relax for better net touch sensitivity.")

        else:  # General Play
            sc.add("stance", _band(m["stance_ratio"], 1.0, 1.6, 0.3), r["feet"], 1.0,
                   "Good ready stance: feet about shoulder-width apart for quick movement.",
                   "Stance width is slightly off. Aim for 1-1.5x shoulder width.",
                   "Adjust stance width to about 1.2x shoulder width for optimal readiness.")
            sc.add("athletic_position", _band(m["mean_knee_angle"], 125, 160, 12), knee_rel, 1.0,
                   "Good knee bend: you're in an athletic ready position.",
                   "Bend your knees slightly more for a faster first step.",
                   "Bend your knees more. An upright stance slows explosive movements.")
            bal = _mean([_band(m["torso_lean_deg"], 0, 20, 10), _band(m["com_offset"], 0, 0.25, 0.15)])
            sc.add("balance", bal, r["torso"], 1.0,
                   "Centered balance, ready to move in any direction.",
                   "Balance is slightly off-centre. Keep your hips over your base.",
                   "Lean is off-center. Maintain a neutral spine for faster reactions.")
            arm = _mean([_band(m["racket_elbow_angle"], 70, 140, 20),
                         _band(m["racket_wrist_height"], -0.6, 0.6, 0.3)])
            sc.add("arm_readiness", arm, arm_rel, 1.0,
                   "Racket arm is up and ready for quick preparation.",
                   "Bring the racket up a little more in front of you.",
                   "Keep the racket up in front with a bent arm for faster racket work.")
            sc.add("grip", _band(closure, 50, 120, 30), rhand and rhand["confidence"], 0.5,
                   "Relaxed grip in ready position allows quick grip changes.",
                   "Grip is slightly tense. Relax between shots.",
                   "Grip looks tight. Relax between shots for better versatility.")
            sc.tips.append("Stay on the balls of your feet for explosive split-step movement.")

        # Match to the reference's ideal joint angles (racket side mapped).
        ideal = self.REFERENCE_POSES[shot_type]["ideal_angles"]
        dev = {}
        parts = []
        for key, val in ideal.items():
            side, joint = key.split("_")
            actual_side = rs if side == "right" else OTHER_SIDE[rs]
            name = f"{actual_side}_{joint}_angle"
            a = body.get(name)
            if a is None:
                continue
            dev[f"{actual_side}_{joint}"] = round(a - val, 1)
            parts.append((_band(abs(a - val), 0, 12, 15), _rel_weight(rel.get(name))))
        if parts:
            fm = sum(s * w for s, w in parts) / sum(w for _, w in parts)
            sc.add("form_match", fm, _mean([rel.get(n) for n in self.ANGLE_DEFS]), 0.8,
                   "Joint angles closely match the model technique.",
                   "Joint angles are close to the model technique; see the angle guide for fine-tuning.",
                   "Several joint angles differ from the model technique; compare with the reference skeleton.")

        overall = sc.overall()
        overall = 50 if overall is None else int(round(overall))
        verdict = self._verdict(overall)
        metrics = {k: int(round(v["score"])) for k, v in sc.items.items()}

        return {
            "stroke_type": shot_type,
            "verdict": verdict,
            "accuracy": overall,
            "feedback": sc.feedback(),
            "metrics": metrics,
            "metric_reliability": {k: v["rel"] for k, v in sc.items.items()},
            "body_angles": {
                "right_shoulder": body["right_shoulder_angle"],
                "left_shoulder": body["left_shoulder_angle"],
                "right_elbow": body["right_elbow_angle"],
                "left_elbow": body["left_elbow_angle"],
                "right_hip": body["right_hip_angle"],
                "left_hip": body["left_hip_angle"],
                "right_knee": body["right_knee_angle"],
                "left_knee": body["left_knee_angle"],
            },
            "ideal_angle_deviation": dev,
            "hand_data": hands,
            "_scorecard": sc,
        }

    @staticmethod
    def _verdict(score):
        if score <= 0:
            return "Unreadable"
        if score >= 80:
            return "Excellent"
        if score >= 65:
            return "Good"
        if score >= 50:
            return "Needs Work"
        return "Poor"

    # ------------------------------------------------------------------ #
    # End-to-end
    # ------------------------------------------------------------------ #
    def run(self, image_path, requested_shot="Auto", handedness="auto"):
        pose_lm, img_w, img_h, _, quality_score, quality_warnings = self.analyze_pose(image_path)
        fallback_type = requested_shot if requested_shot in self.REFERENCE_POSES else "General Play"

        if pose_lm is None:
            final_warnings = quality_warnings or [
                "No person detected. Ensure your full body is visible in the frame from head to toe."]
            return {
                "stroke_type": fallback_type, "verdict": "Unreadable", "accuracy": 0,
                "feedback": final_warnings[0], "metrics": {}, "body_angles": {},
                "hand_data": {"hands_detected": 0},
                "similarity_score": 0,
                "sub_scores": {"footwork": 0, "posture": 0, "swing": 0},
                "reference_skeleton": self.get_reference_skeleton(fallback_type),
                "detected_skeleton": None,
                "quality_score": quality_score, "quality_warnings": final_warnings,
                "detected_shot_type": fallback_type, "detection_confidence": 0,
                "shot_type_scores": {}, "all_similarities": {},
                "analysis_confidence": 0,
                "requested_shot_type": requested_shot,
                "engine": self._engine_info(),
            }

        world = self.last_world
        racket_side, side_conf, side_src = self.determine_racket_side(pose_lm, img_w, img_h, handedness)
        body = self.extract_body_metrics(pose_lm, img_w, img_h, world=world,
                                         refined=self.last_refined, racket_side=racket_side)
        hands_found = self.detect_hands(self.last_image, pose_lm)
        hand_metrics = self.extract_hand_metrics(hands_found, img_w, img_h, racket_side)

        # ---- CONFIDENCE GATING ----
        reliability = body["reliability"]
        low_metrics = [n for n, v in reliability.items() if v is not None and v < 0.45]
        if low_metrics:
            quality_warnings.append(
                "Low joint-detection confidence on: " + ", ".join(low_metrics) +
                ". The swing arm may be blurred or occluded, so related scores may be "
                "inaccurate. Use a sharper, well-lit photo (or a short video for the most "
                "reliable results).")
        unreliable_major = len(low_metrics) >= 3

        strong_foreshorten = [n for n, d in body["foreshortening"].items() if d is not None and d > 45]
        if strong_foreshorten:
            quality_warnings.append(
                "Camera angle compressed the view of: " + ", ".join(strong_foreshorten) +
                " (flat 2D vs depth-corrected 3D differs by 45°+). Scores use the 3D estimate.")

        if side_src == "auto" and side_conf < 0.7:
            quality_warnings.append(
                f"Racket arm assumed to be the {racket_side} arm. If that's wrong, set "
                "handedness for a more accurate swing analysis.")

        # ---- SIMILARITY (all references) ----
        user_norm = self.normalize_landmarks(pose_lm, img_w, img_h)
        user_vis = {i: pose_lm[i].visibility for i in KEY_JOINTS}
        if side_src == "user" or side_conf >= 0.75:
            swap_opts = (racket_side == "left",)
        else:
            swap_opts = (False, True)
        sims = {t: self.best_similarity(user_norm, t, user_vis, swap_opts) for t in self.REFERENCE_POSES}
        all_similarities = {t: s["similarity"] for t, s in sims.items()}

        # ---- SHOT TYPE ----
        detected_type, detection_confidence, type_scores = self.auto_detect_shot_type(
            body, hand_metrics, all_similarities,
            requested_shot if requested_shot in self.REFERENCE_POSES else None)
        if requested_shot in self.REFERENCE_POSES and detected_type != requested_shot:
            quality_warnings.append(
                f"You selected {requested_shot}, but the pose looks more like {detected_type} "
                f"({detection_confidence}% confidence), so it was scored as {detected_type}.")
        ranked = sorted(type_scores.items(), key=lambda kv: -kv[1])
        if {ranked[0][0], ranked[1][0]} == {"Smash", "Cross Drop"} and ranked[0][1] - ranked[1][1] < 8:
            quality_warnings.append(
                "Smash and Cross Drop look almost identical at this moment of the swing "
                "(that's the point of the deception). Select the shot type for the most accurate scoring.")

        analysis = self.score_shot(detected_type, body, hand_metrics)
        sc = analysis.pop("_scorecard")

        # ---- SUB-SCORES (reliability-weighted) ----
        sub = {}
        for cat, names in self.SUB_SCORE_MAP.items():
            v = sc.overall(names)
            sub[cat] = int(round(v)) if v is not None else analysis["accuracy"]

        accuracy = analysis["accuracy"]
        if unreliable_major and accuracy > 65:
            accuracy = 65
        # ACCURACY NOTE: v1 subtracted up to 15 points for a poor photo, which
        # mixes photo quality into the technique score. Photo quality is now
        # reported separately as analysis_confidence.
        analysis_conf = int(round(100 * sc.mean_reliability() * (0.6 + 0.4 * quality_score / 100.0)))

        best = sims[detected_type]
        analysis.update({
            "accuracy": accuracy,
            "verdict": self._verdict(accuracy),
            "similarity_score": best["similarity"],
            "sub_scores": sub,
            "reference_skeleton": self.get_reference_skeleton(
                detected_type, best["flip_x"], best["swap"], best["aligned"], img_w, img_h),
            "detected_skeleton": self.extract_detected_skeleton(pose_lm, img_w, img_h),
            "quality_score": quality_score,
            "quality_warnings": quality_warnings,
            "detected_shot_type": detected_type,
            "detection_confidence": detection_confidence,
            "shot_type_scores": type_scores,
            "all_similarities": all_similarities,
            "analysis_confidence": analysis_conf,
            "requested_shot_type": requested_shot,
            "racket_side": racket_side,
            "racket_side_confidence": side_conf,
            "racket_side_source": side_src,
            "measurements": {k: v for k, v in body["measurements"].items() if k != "torso_length_px"},
            "joint_reliability": reliability,
            "angle_source": body["angle_source"],
            "engine": self._engine_info(),
        })
        return analysis

    def _engine_info(self):
        return {"version": ENGINE_VERSION, "model_complexity": self.model_complexity,
                "views_analyzed": self.views_used, "notes": self.engine_notes}


def main():
    """Main entry point: receive image path + shot type (+ handedness), output JSON."""
    real_stdout = sys.stdout
    if len(sys.argv) < 3:
        print(json.dumps({"success": False,
                          "error": "Usage: analysis_engine.py <image_path> <shot_type|Auto> [right|left|auto]"}))
        sys.exit(1)

    image_path = sys.argv[1]
    requested = sys.argv[2].strip()
    handedness = sys.argv[3].strip().lower() if len(sys.argv) > 3 else "auto"
    valid = list(BadmintonAnalyzer.REFERENCE_POSES)
    match = {v.lower(): v for v in valid}.get(requested.lower())
    requested = match or "Auto"
    if handedness not in ("right", "left"):
        handedness = "auto"

    if not os.path.exists(image_path):
        print(json.dumps({"success": False, "error": f"Image not found: {image_path}"}))
        sys.exit(1)

    try:
        # Anything MediaPipe/TF prints goes to stderr, never into our JSON.
        with contextlib.redirect_stdout(sys.stderr):
            with BadmintonAnalyzer() as analyzer:
                data = analyzer.run(image_path, requested, handedness)
        real_stdout.write(json.dumps({"success": True, "data": _clean(data)}) + "\n")
        real_stdout.flush()
    except Exception as e:
        real_stdout.write(json.dumps({"success": False, "error": str(e)}) + "\n")
        real_stdout.flush()
        sys.exit(1)


if __name__ == "__main__":
    main()
