#!/bin/sh
# Meridian Field iOS release archive and export (M19.22).
#
# Runs on a macOS machine with Xcode: archives the committed Capacitor iOS
# project (M19.21) with manual release signing and exports a distributable
# .ipa for App Store Connect / TestFlight (technical spec 26.5).
#
# Every signing credential is supplied through the environment and held
# outside the repository; none has a default in this script or anywhere else
# in the repo. The inventory is mirrored by apps/mobile/src/releaseSigning.ts,
# which is what the config tests hold this script against:
#
#   MERIDIAN_IOS_TEAM_ID                  Apple Developer team identifier.
#   MERIDIAN_IOS_DISTRIBUTION_CERTIFICATE Distribution signing identity name
#                                         (e.g. "Apple Distribution: ...").
#   MERIDIAN_IOS_PROVISIONING_PROFILE     Name of the App Store provisioning
#                                         profile for org.meridian.field.
#
# A build that cannot resolve all three fails here, before Xcode is invoked,
# rather than falling back to automatic or debug signing and emitting an
# artifact that looks distributable.
set -eu

missing=""
for name in MERIDIAN_IOS_TEAM_ID MERIDIAN_IOS_DISTRIBUTION_CERTIFICATE MERIDIAN_IOS_PROVISIONING_PROFILE; do
    if [ -z "$(printenv "$name" || true)" ]; then
        missing="$missing $name"
    fi
done
if [ -n "$missing" ]; then
    echo "Missing iOS release signing credentials:$missing" >&2
    echo "Technical spec 26.5 holds signing credentials outside the repository and" >&2
    echo "supplies them through the environment. Refusing to archive without them." >&2
    exit 1
fi

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BUNDLE_ID="org.meridian.field"
BUILD_DIR="$APP_DIR/ios/App/build"
ARCHIVE_PATH="$BUILD_DIR/MeridianField.xcarchive"
EXPORT_PATH="$BUILD_DIR/export"
EXPORT_OPTIONS="$BUILD_DIR/ExportOptions.plist"

# The wrapper packages an already built client artifact (technical spec 26.4);
# it never builds one implicitly, so a stale or missing copied bundle is a
# refusal, not a trigger.
if [ ! -f "$APP_DIR/ios/App/App/public/index.html" ]; then
    echo "The copied Meridian Field web bundle is missing at ios/App/App/public." >&2
    echo "Run 'corepack pnpm run cap:sync' first (technical spec 26.4)." >&2
    exit 1
fi

mkdir -p "$BUILD_DIR"

xcodebuild archive \
    -project "$APP_DIR/ios/App/App.xcodeproj" \
    -scheme App \
    -configuration Release \
    -destination "generic/platform=iOS" \
    -archivePath "$ARCHIVE_PATH" \
    CODE_SIGN_STYLE=Manual \
    DEVELOPMENT_TEAM="$MERIDIAN_IOS_TEAM_ID" \
    CODE_SIGN_IDENTITY="$MERIDIAN_IOS_DISTRIBUTION_CERTIFICATE" \
    PROVISIONING_PROFILE_SPECIFIER="$MERIDIAN_IOS_PROVISIONING_PROFILE"

# ExportOptions.plist is generated into the gitignored build directory from
# the same environment credentials, never committed: it names the team, the
# distribution certificate, and the provisioning profile for the export.
cat > "$EXPORT_OPTIONS" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>method</key>
    <string>app-store-connect</string>
    <key>destination</key>
    <string>export</string>
    <key>teamID</key>
    <string>${MERIDIAN_IOS_TEAM_ID}</string>
    <key>signingStyle</key>
    <string>manual</string>
    <key>signingCertificate</key>
    <string>${MERIDIAN_IOS_DISTRIBUTION_CERTIFICATE}</string>
    <key>provisioningProfiles</key>
    <dict>
        <key>${BUNDLE_ID}</key>
        <string>${MERIDIAN_IOS_PROVISIONING_PROFILE}</string>
    </dict>
</dict>
</plist>
EOF

xcodebuild -exportArchive \
    -archivePath "$ARCHIVE_PATH" \
    -exportOptionsPlist "$EXPORT_OPTIONS" \
    -exportPath "$EXPORT_PATH"

echo "Archive: $ARCHIVE_PATH"
echo "Export:  $EXPORT_PATH"
