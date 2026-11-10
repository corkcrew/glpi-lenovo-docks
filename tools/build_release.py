"""Build an installable GLPI archive without development or server-specific files."""

from pathlib import Path
import hashlib
import re
import zipfile

root = Path(__file__).resolve().parents[1]
source = (root / "setup.php").read_text(encoding="utf-8")
match = re.search(r"['\"]version['\"]\s*=>\s*['\"]([^'\"]+)['\"]", source)
if not match:
    raise SystemExit("Plugin version missing in setup.php")
version = match.group(1)
output = root / "dist"
output.mkdir(exist_ok=True)
archive = output / f"itcoreinventory-{version}.zip"
allowed = ["setup.php", "hook.php", "docks.json", "LICENSE", "README.md", "CHANGELOG.md"]
allowed += ["inc", "src", "front", "public", "templates", "examples"]
files = []
for name in allowed:
    path = root / name
    files.extend([path] if path.is_file() else sorted(p for p in path.rglob("*") if p.is_file()))
with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as package:
    for path in files:
        info = zipfile.ZipInfo("itcoreinventory/" + path.relative_to(root).as_posix())
        info.create_system = 3
        info.external_attr = 0o100644 << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        package.writestr(info, path.read_bytes())
checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
(output / (archive.name + ".sha256")).write_text(f"{checksum}  {archive.name}\n", encoding="ascii")
print(archive)
print("SHA256:", checksum)
