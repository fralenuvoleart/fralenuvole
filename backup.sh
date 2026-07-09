#!/bin/bash

# --- CONFIGURATION ---
WORKSPACE_DIR="/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/fralenuvole/"
BACKUP_DIR="/mnt/backup/BACKUP/WEB-BACKUP/FRALENUVOLE"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
ZIP_NAME="fralenuvole_$TIMESTAMP.zip"

# --- CUSTOM ROOT EXCLUDE LIST ---
# Anything listed here will be left out of the ZIP archive.
# Your actual workspace files remain perfectly safe and untouched.
EXCLUDE_LIST=(
    "node_modules"
    "vendor"
    "*.tmp"
    "*.log"
)

# --- ZIP & COPY (READ ONLY) ---
echo "📦 Creating zip archive..."

# Ensure the backup directory exists
mkdir -p "$BACKUP_DIR"

# Dynamically extract the folder name safely
FOLDER_NAME=$(basename "$WORKSPACE_DIR")

# Move to the parent directory of the plugin
cd "$(dirname "$WORKSPACE_DIR")" || exit 1

# 1. AUTOMATICALLY EXCLUDE ALL ROOT DOT-ITEMS FROM THE ZIP
ZIP_EXCLUDES=(
    "-x" "$FOLDER_NAME/.*"
    "-x" "$FOLDER_NAME/.*/*"
)

# 2. DYNAMICALLY ADD YOUR CUSTOM EXCLUSIONS TO THE ZIP
for item in "${EXCLUDE_LIST[@]}"; do
    ZIP_EXCLUDES+=("-x" "$FOLDER_NAME/$item" "-x" "$FOLDER_NAME/$item/*")
done

# Run the zip command (Reads your workspace, writes to backup destination)
if zip -r "$BACKUP_DIR/$ZIP_NAME" "$FOLDER_NAME" "${ZIP_EXCLUDES[@]}"; then
    echo "✅ Backup successfully saved to: $BACKUP_DIR/$ZIP_NAME"
    echo "🔒 Your live workspace was not modified."
else
    echo "❌ Error: Failed to create the zip archive."
    exit 1
fi