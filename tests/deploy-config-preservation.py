"""Exercise the production packager against a disposable exact-source commit."""
from pathlib import Path
from tempfile import TemporaryDirectory
import hashlib
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]


def run(*args, cwd):
    return subprocess.check_output(args, cwd=cwd, text=True).strip()


with TemporaryDirectory(prefix='sf-deploy-config-') as directory:
    fixture = Path(directory)
    repo = fixture / 'source'
    repo.mkdir()
    contents = {
        'index.php': '<?php // index fixture\n',
        'upgrade.php': '<?php // upgrade fixture\n',
        'admin/index.php': '<?php // admin fixture\n',
        'api/bootstrap.php': (ROOT / 'api/bootstrap.php').read_text(),
        'config/store.default.php': (ROOT / 'config/store.default.php').read_text(),
        'config/store.php': '<?php return ["mode"=>"production"];\n',
        'config/database.php': '<?php // host database secret\n',
        'config/secrets.php': '<?php // host provider secret\n',
        '.env': 'PROVIDER_SECRET=private-fixture\n',
        '.env.local': 'PROVIDER_SECRET=private-local-fixture\n',
        'storage/media/production.mp3': 'runtime media fixture\n',
        'uploads/photo.jpg': 'user image fixture\n',
        'data/catalog.json': '[{"id":"host-catalog"}]\n',
        'assets/js/fixture.js': 'window.fixture=true;\n',
        '.github/workflows/fixture.yml': 'name: excluded CI\n',
        'tests/fixture.php': '<?php // excluded test\n',
        'speech-gateway/fixture.js': '// separate gateway\n',
        'DEPLOY-MANIFEST.txt': 'obsolete static manifest\n',
        'scripts/release-zip.sh': (ROOT / 'scripts/release-zip.sh').read_text(),
    }
    required_assets = (
        'api/canvas-data.php', 'api/canvas-geography-core.php', 'api/canvas-map-core.php', 'api/canvas-earthquakes-core.php',
        'assets/js/canvas-data-picker.js', 'assets/js/canvas-location.js',
        'api/canvas-libraries.php', 'api/canvas-library-core.php', 'api/media-artwork-palette.php',
        'assets/js/canvas-library-runtime.js', 'assets/js/media-artwork-palette.js',
        *(f'assets/vendor/canvas/{library}.{ext}' for library in ('color-thief', 'three') for ext in ('js', 'LICENSE')),
        'config/canvas-maps/us-regions.json', 'config/canvas-maps/us-overview.json',
        *(f'config/canvas-data-places/us-places-{part:02}.tsv' for part in range(1, 4)),
    )
    contents.update({name: (ROOT / name).read_text(encoding='utf-8') for name in required_assets})
    for name, content in contents.items():
        path = repo / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content, encoding='utf-8')
    run('git', 'init', '-q', cwd=repo)
    run('git', 'config', 'user.email', 'fixture@example.invalid', cwd=repo)
    run('git', 'config', 'user.name', 'Fixture', cwd=repo)
    run('git', 'add', '.', cwd=repo)
    run('git', 'commit', '-qm', 'Disposable source fixture', cwd=repo)
    commit = run('git', 'rev-parse', 'HEAD', cwd=repo)
    output = fixture / 'dist'
    run('bash', 'scripts/release-zip.sh', str(output), cwd=repo)
    package = output / 'stonefellow-deploy.zip'
    checksum = (output / 'stonefellow-deploy.zip.sha256').read_text().split()[0]
    assert hashlib.sha256(package.read_bytes()).hexdigest() == checksum
    with zipfile.ZipFile(package) as archive:
        assert archive.testzip() is None
        names = archive.namelist()
        assert 'index.php' in names and 'config/store.default.php' in names
        assert set(required_assets) <= set(names)
        assert not any(name.endswith('.zip') for name in names)
        assert not any(name.startswith(('storage/', 'uploads/', 'data/', '.github/', 'tests/', 'scripts/', 'speech-gateway/')) for name in names)
        assert not any(name in names for name in ('config/store.php', 'config/database.php', 'config/secrets.php', '.env', '.env.local'))
        assert f'Source commit: {commit}' in archive.read('DEPLOY-MANIFEST.txt').decode()
        for name in names:
            if name.endswith('/') or name == 'DEPLOY-MANIFEST.txt':
                continue
            expected = subprocess.check_output(('git', 'show', f'{commit}:{name}'), cwd=repo)
            assert archive.read(name) == expected, f'Changed source payload: {name}'
        host = fixture / 'host'
        (host / 'config').mkdir(parents=True)
        (host / 'storage/media').mkdir(parents=True)
        (host / 'data').mkdir(parents=True)
        (host / 'config/store.php').write_text('host-custom-configuration')
        (host / 'storage/media/production.mp3').write_text('host-production-media')
        (host / 'data/catalog.json').write_text('host-catalog')
        archive.extractall(host)
        assert (host / 'config/store.php').read_text() == 'host-custom-configuration'
        assert (host / 'storage/media/production.mp3').read_text() == 'host-production-media'
        assert (host / 'data/catalog.json').read_text() == 'host-catalog'
    print('Deployment source equality, ZIP integrity, root extraction, and host config/media preservation passed')
