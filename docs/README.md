# Installer Binaries

QZ Tray installer binaries are intentionally NOT tracked in git (the old
0-byte placeholders caused `GET /qz/installer/{os}` to serve broken files).

To offer local downloads, fetch the official binaries from
https://qz.io/download/ and place them here (or in
`public/vendor/qz-tray/installers/` after publishing):

- qz-tray-windows.exe
- qz-tray-macos.pkg
- qz-tray-linux.deb

`installer()` automatically serves them once a non-empty file is present.
