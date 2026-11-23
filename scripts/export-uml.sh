#!/usr/bin/env bash
# ============================================================================
# SideKey — export des diagrammes PlantUML
#
# Régénère docs/uml/exports/*.png et *.svg à partir de docs/uml/src/*.puml.
# Deux modes, dans cet ordre de préférence :
#   1. PlantUML installé localement (binaire `plantuml` dans le PATH) ;
#   2. image Docker `plantuml/plantuml` (Graphviz inclus), sans installation.
#
# Usage :
#   scripts/export-uml.sh              # tous les diagrammes, PNG + SVG
#   scripts/export-uml.sh -tsvg        # un seul format
#   scripts/export-uml.sh -tpng -tsvg  # plusieurs formats
#
# Variables d'environnement :
#   PLANTUML_IMAGE   image Docker (défaut : plantuml/plantuml:1.2026.10)
# ============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOCS="$ROOT/docs/uml"
SRC="$DOCS/src"
OUT="$DOCS/exports"
IMAGE="${PLANTUML_IMAGE:-plantuml/plantuml:1.2026.10}"

if [ ! -d "$SRC" ]; then
  echo "Dossier introuvable : $SRC" >&2
  exit 1
fi

mkdir -p "$OUT"

if [ "$#" -ge 1 ]; then
  FORMATS=("$@")
else
  FORMATS=(-tpng -tsvg)
fi

nb_sources=$(find "$SRC" -maxdepth 1 -name '*.puml' | wc -l | tr -d ' ')
if [ "$nb_sources" -eq 0 ]; then
  echo "Aucune source .puml dans $SRC" >&2
  exit 1
fi

echo "Export de $nb_sources diagramme(s) vers $OUT"

if command -v plantuml >/dev/null 2>&1; then
  echo "Mode : PlantUML local ($(command -v plantuml))"
  for fmt in "${FORMATS[@]}"; do
    plantuml "$fmt" -o "$OUT" "$SRC"
  done
elif command -v docker >/dev/null 2>&1; then
  echo "Mode : Docker ($IMAGE)"
  for fmt in "${FORMATS[@]}"; do
    # MSYS_NO_PATHCONV : évite la réécriture des chemins /data/... dans Git Bash (Windows)
    MSYS_NO_PATHCONV=1 docker run --rm \
      -v "$DOCS:/data" \
      "$IMAGE" \
      "$fmt" -o /data/exports /data/src
  done
else
  echo "PlantUML n'est pas disponible." >&2
  echo "Installez PlantUML (https://plantuml.com/download) ou Docker (https://docs.docker.com/get-docker/)." >&2
  exit 1
fi

echo "Terminé. Fichiers produits :"
ls -1 "$OUT" | sed 's/^/  /'
