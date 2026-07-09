#!/bin/bash

# --- CONFIGURATION ---
WORKSPACE_DIR="/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/fralenuvole/"
BACKUP_DIR="/mnt/backup/BACKUP/WEB-BACKUP/FRALENUVOLE"
MAIN_PLUGIN_FILE="$WORKSPACE_DIR/fralenuvole.php"

# 1. DYNAMICALLY EXTRACT PLUGIN VERSION
# Reads the 'Version: X.X.X' line from fralenuvole.php
if [ -f "$MAIN_PLUGIN_FILE" ]; then
    PLUGIN_VERSION=$(grep -m 1 -i "Version:" "$MAIN_PLUGIN_FILE" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]\r\n')
else
    PLUGIN_VERSION="latest"
fi

# 2. TIMESTAMP FORMAT (YYYYMMDDHHMM)
TIMESTAMP=$(date +%Y%m%d%H%M)

# 3. ZIP NAME FORMAT (fralenuvole-VERSION-TIMESTAMP.zip)
ZIP_NAME="fralenuvole-${PLUGIN_VERSION}-${TIMESTAMP}.zip"

# --- CUSTOM ROOT EXCLUDE LIST ---
EXCLUDE_LIST=(
    "vendor"
    "composer*"
    "plans"
    "phpcs.xml"
    "*.sh"
    "*.md"
)

# --- ZIP & COPY (READ ONLY) ---
echo "🔎 Extracted Plugin Version: $PLUGIN_VERSION"
echo "📦 Creating zip archive..."

# Ensure the backup directory exists
mkdir -p "$BACKUP_DIR"

# Dynamically extract the folder name safely
FOLDER_NAME=$(basename "$WORKSPACE_DIR")

# Move to the parent directory of the plugin
cd "$(dirname "$WORKSPACE_DIR")" || exit 1

# Automatically exclude all root dot-items from the zip
ZIP_EXCLUDES=(
    "-x" "$FOLDER_NAME/.*"
    "-x" "$FOLDER_NAME/.*/*"
)

# Dynamically add your custom exclusions to the zip
for item in "${EXCLUDE_LIST[@]}"; do
    ZIP_EXCLUDES+=("-x" "$FOLDER_NAME/$item" "-x" "$FOLDER_NAME/$item/*")
done

# Run the zip command
if zip -r "$BACKUP_DIR/$ZIP_NAME" "$FOLDER_NAME" "${ZIP_EXCLUDES[@]}"; then
    echo "✅ Backup successfully saved to: $BACKUP_DIR/$ZIP_NAME"
else
    echo "❌ Error: Failed to create the zip archive."
    exit 1
fi