"""CI-G1 Cloud release policy: no draft full-suite, no main release erosion."""
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest.mock import patch
import importlib.util

root = Path(__file__).resolve().parents[1]
source = (root / ".github/workflows/release-gate.yml").read_text(encoding="utf-8")
assert "      - 'feat/**'" not in source
assert "  push:\n    branches:\n      - main" in source
assert "types: [opened, reopened, synchronize, ready_for_review]" in source
assert "  pr-smoke:" in source
assert "if: github.event_name == 'pull_request'" in source
assert source.count("github.event.pull_request.draft == false") == 2
assert "  release-gate:" in source and "  mysql-certification:" in source
assert "scripts/ci_pr_smoke.py" in source
cert=(root / ".github/workflows/certified-deploy.yml").read_text(encoding="utf-8")
assert "branches: [main]" in cert
assert "github.event.workflow_run.conclusion == 'success'" in cert
assert "ref: ${{ github.event.workflow_run.head_sha }}" in cert

spec=importlib.util.spec_from_file_location("ci_pr_smoke",root/"scripts/ci_pr_smoke.py")
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
with TemporaryDirectory() as dirpath:
    base = Path(dirpath)
    for filename in ("good.php", "good.js", "good.mjs", "notes.md"):
        (base/filename).write_text("test",encoding="utf-8")
    with patch("pathlib.Path.is_file",return_value=True):
        commands=module.syntax_commands(["good.php","good.js","good.mjs","notes.md"])
    assert ["php","-l","good.php"] in commands
    assert ["node","--check","good.js"] in commands
    assert ["node","--check","good.mjs"] in commands
    assert len(commands)==3
    try:
        module.syntax_commands(["../outside.php"])
    except ValueError:
        pass
    else:
        raise AssertionError("Traversal must be rejected")
    try:
        module.changed_files("not-a-sha")
    except ValueError:
        pass
    else:
        raise AssertionError("Unknown PR base must fail closed")
print("CI-G1 Cloud: draft smoke, full ready-to-review MySQL and certified main verified")
