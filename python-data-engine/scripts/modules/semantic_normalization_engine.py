from __future__ import annotations

from datetime import date
from typing import Any

import db


def _safe_str(value: Any) -> str | None:
    if value is None:
        return None
    if isinstance(value, str):
        out = value.strip()
        return out if out != "" else None
    if isinstance(value, (int, float)):
        return str(value)
    return None


def _norm_text(value: str | None) -> str | None:
    if value is None:
        return None
    out = " ".join(value.strip().lower().replace(",", " ").split())
    return out if out != "" else None


class DateNormalizer:
    MONTHS = {
        "jan": 1,
        "january": 1,
        "feb": 2,
        "february": 2,
        "mar": 3,
        "march": 3,
        "apr": 4,
        "april": 4,
        "may": 5,
        "jun": 6,
        "june": 6,
        "jul": 7,
        "july": 7,
        "aug": 8,
        "august": 8,
        "sep": 9,
        "sept": 9,
        "september": 9,
        "oct": 10,
        "october": 10,
        "nov": 11,
        "november": 11,
        "dec": 12,
        "december": 12,
    }

    @classmethod
    def to_iso(cls, value: Any) -> str | None:
        raw = _safe_str(value)
        if raw is None:
            return None
        s = raw.strip()
        if len(s) == 10 and s[4] == "-" and s[7] == "-":
            y, m, d = s.split("-")
            if y.isdigit() and m.isdigit() and d.isdigit():
                return cls._safe_date(int(y), int(m), int(d))

        if "/" in s:
            parts = s.split("/")
            if len(parts) == 3 and all(p.isdigit() for p in parts):
                d, m, y = parts
                return cls._safe_date(int(y), int(m), int(d))

        if "-" in s and len(s.split("-")) == 3 and s[:4].isdigit() is False:
            parts = s.split("-")
            if all(p.isdigit() for p in parts):
                d, m, y = parts
                return cls._safe_date(int(y), int(m), int(d))

        tokens = s.replace(",", " ").split()
        if len(tokens) == 3 and tokens[0].isdigit() and tokens[2].isdigit():
            d = int(tokens[0])
            month = cls.MONTHS.get(tokens[1].strip().lower())
            y = int(tokens[2])
            if month is not None:
                return cls._safe_date(y, month, d)

        return None

    @staticmethod
    def _safe_date(year: int, month: int, day: int) -> str | None:
        try:
            return date(year, month, day).isoformat()
        except ValueError:
            return None


class RelationLabelResolver:
    FIELD_TABLE_MAP = {
        "caste": ("castes", "label"),
        "religion": ("religions", "label"),
        "marital_status": ("master_marital_statuses", "label"),
        "gender": ("master_genders", "label"),
        "mother_tongue": ("master_mother_tongues", "label"),
        "occupation": ("occupation_masters", "title"),
        "education": ("education_degrees", "name"),
        "family_type": ("master_family_types", "label"),
        "complexion": ("master_complexions", "label"),
        "blood_group": ("master_blood_groups", "label"),
        "nakshatra": ("master_nakshatras", "name"),
        "rashi": ("master_rashis", "name"),
        "mangal_dosh": ("master_mangal_dosh_types", "label"),
        "income_range": ("income_ranges", "label"),
        "professions": ("professions", "name"),
    }

    def __init__(self, conn: Any):
        self.conn = conn
        self._cache: dict[str, dict[int, str]] = {}

    def label_for_id(self, field: str, value: Any) -> str | None:
        raw = _safe_str(value)
        if raw is None or not raw.isdigit():
            return None
        row_id = int(raw)
        table_meta = self.FIELD_TABLE_MAP.get(field)
        if table_meta is None:
            return None
        table, label_col = table_meta
        if field not in self._cache:
            self._cache[field] = self._load_map(table, label_col)
        label = self._cache[field].get(row_id)
        return _safe_str(label)

    def _load_map(self, table: str, label_col: str) -> dict[int, str]:
        sql = f"SELECT id, {label_col} AS label FROM {table}"
        try:
            rows = db.fetch_all(self.conn, sql)
        except Exception:
            return {}
        out: dict[int, str] = {}
        for r in rows:
            try:
                rid = int(r.get("id"))
            except Exception:
                continue
            label = _safe_str(r.get("label"))
            if label is None:
                continue
            out[rid] = label
        return out


class LocationNormalizer:
    def __init__(self, conn: Any):
        self.conn = conn
        self._node_cache: dict[int, dict[str, Any]] = {}
        self._variant_cache: dict[int, set[str]] = {}

    def variants_for_id(self, value: Any) -> set[str]:
        raw = _safe_str(value)
        if raw is None or not raw.isdigit():
            return set()
        node_id = int(raw)
        if node_id in self._variant_cache:
            return self._variant_cache[node_id]

        chain = self._build_chain(node_id)
        variants: set[str] = set()
        if not chain:
            self._variant_cache[node_id] = variants
            return variants

        leaf = chain[0]
        leaf_name = _norm_text(_safe_str(leaf.get("name")))
        district_name = None
        for item in chain:
            if str(item.get("type") or "").lower() == "district":
                district_name = _norm_text(_safe_str(item.get("name")))
                break
        if district_name is None and len(chain) >= 3:
            district_name = _norm_text(_safe_str(chain[2].get("name")))
        pincode = _safe_str(leaf.get("pincode"))

        if leaf_name is not None:
            variants.add(leaf_name)
        if leaf_name is not None and district_name is not None:
            variants.add(f"{leaf_name} {district_name}")
            if pincode is not None:
                variants.add(f"{leaf_name} {district_name} {pincode}")

        self._variant_cache[node_id] = variants
        return variants

    def _build_chain(self, start_id: int) -> list[dict[str, Any]]:
        out: list[dict[str, Any]] = []
        current = start_id
        seen: set[int] = set()
        for _ in range(8):
            if current in seen:
                break
            seen.add(current)
            node = self._get_node(current)
            if node is None:
                break
            out.append(node)
            parent_id = node.get("parent_id")
            if parent_id is None:
                break
            try:
                current = int(parent_id)
            except Exception:
                break
        return out

    def _get_node(self, node_id: int) -> dict[str, Any] | None:
        if node_id in self._node_cache:
            return self._node_cache[node_id]
        try:
            rows = db.fetch_all(
                self.conn,
                "SELECT id, parent_id, name, pincode, type FROM addresses WHERE id = %s LIMIT 1",
                (node_id,),
            )
        except Exception:
            return None
        if not rows:
            return None
        node = rows[0]
        self._node_cache[node_id] = node
        return node


class SemanticNormalizationEngine:
    def __init__(self, conn: Any):
        self.date = DateNormalizer()
        self.relations = RelationLabelResolver(conn)
        self.location = LocationNormalizer(conn)

    def is_semantic_equivalent(self, field: str, db_v: Any, api_v: Any, rendered_v: Any) -> bool:
        rendered_norm = _norm_text(_safe_str(rendered_v))
        if rendered_norm is None:
            return False

        if field == "date_of_birth":
            db_iso = self.date.to_iso(db_v)
            ren_iso = self.date.to_iso(rendered_v)
            return db_iso is not None and ren_iso is not None and db_iso == ren_iso

        label = self.relations.label_for_id(field, db_v)
        if label is not None and _norm_text(label) == rendered_norm:
            return True

        if field == "city":
            variants = self.location.variants_for_id(db_v)
            return rendered_norm in variants

        return False
