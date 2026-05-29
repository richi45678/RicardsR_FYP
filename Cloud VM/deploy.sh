#!/usr/bin/env bash
# Deploy Smart Garden PHP from this directory to Apache docroot.
set -euo pipefail

DEST="${1:-/var/www/html}"
SRC="$(cd "$(dirname "$0")" && pwd)"

FILES=(
  index.php
  esp_capture_lib.php
  camera_config.php
  upload.php
  list_uploads.php
  delete_photos.php
  plant_meta.php
  plant_analyze_api.php
  plant_analyze_worker.php
  pump_capture_lib.php
  sensor_lib.php
  sensor_api.php
  sensor_config.php
  sensor_history_lib.php
  sensor_history.php
  monitored_plant_lib.php
  monitored_plant_api.php
  plant_ai_lib.php
  tank_notifications_lib.php
  tank_notifications_api.php
  tank_notifications_smtp.php
)

for f in "${FILES[@]}"; do
  if [[ -f "$SRC/$f" ]]; then
    sudo cp "$SRC/$f" "$DEST/$f"
  fi
done

if [[ -d "$SRC/assets" ]]; then
  sudo mkdir -p "$DEST/assets"
  sudo cp -r "$SRC/assets/"* "$DEST/assets/"
fi

echo "Deployed to $DEST"
