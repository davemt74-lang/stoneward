#!/usr/bin/env python3
"""Rebuild the packaged public place catalog from a Census national Places Gazetteer ZIP."""

import argparse
import collections
import csv
import io
import re
import zipfile
from pathlib import Path

SUFFIXES = {
    '21': 'borough', '25': 'city', '37': 'municipality', '43': 'town', '47': 'village',
    '53': 'city and borough', '55': 'comunidad', '57': 'CDP', '62': 'zona urbana',
    'CG': 'consolidated government', 'CN': 'corporation', 'MG': 'metropolitan government',
    'UC': 'urban county', 'UG': 'unified government',
}
# Common city labels for consolidated governments and the Urban Honolulu Census place.
PUBLIC_NAMES = {
    ('GA', 'Athens-Clarke County unified government (balance)'): 'Athens',
    ('GA', 'Augusta-Richmond County consolidated government (balance)'): 'Augusta',
    ('GA', 'Macon-Bibb County'): 'Macon',
    ('HI', 'Urban Honolulu CDP'): 'Honolulu',
    ('IN', 'Indianapolis city (balance)'): 'Indianapolis',
    ('KY', 'Lexington-Fayette urban county'): 'Lexington',
    ('KY', 'Louisville/Jefferson County metro government (balance)'): 'Louisville',
    ('TN', 'Nashville-Davidson metropolitan government (balance)'): 'Nashville',
}


def rows(zip_path):
    with zipfile.ZipFile(zip_path) as archive:
        files = [name for name in archive.namelist() if re.fullmatch(r'\d{4}_Gaz_place_national\.txt', name)]
        if len(files) != 1:
            raise ValueError('Choose a Census national Places Gazetteer ZIP.')
        with io.TextIOWrapper(archive.open(files[0]), encoding='utf-8-sig') as table:
            for row in csv.DictReader(table, delimiter='|'):
                geoid, state, official = (row[field].strip() for field in ('GEOID', 'USPS', 'NAME'))
                if not re.fullmatch(r'\d{7}', geoid) or not re.fullmatch(r'[A-Z]{2}', state) or not official:
                    raise ValueError(f'Invalid Census place record {geoid}.')
                display = PUBLIC_NAMES.get((state, official), official)
                if display == official and row['LSAD'] in SUFFIXES:
                    display = official.removesuffix(' ' + SUFFIXES[row['LSAD']])
                yield geoid, state, display, official, float(row['INTPTLAT']), float(row['INTPTLONG'])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('gazetteer', type=Path)
    args = parser.parse_args()
    places = sorted(rows(args.gazetteer))
    display_counts = collections.Counter((state, name) for _, state, name, *_ in places)
    official_counts = collections.Counter((state, official) for _, state, _, official, *_ in places)
    out = Path(__file__).resolve().parents[1] / 'config' / 'canvas-data-places'
    out.mkdir(parents=True, exist_ok=True)
    lines = []
    for geoid, state, name, official, lat, lon in places:
        # Use the legal designation and, when necessary, the place code to distinguish same-state duplicates.
        label = ''
        if display_counts[state, name] > 1:
            label = official if official_counts[state, official] == 1 else f'{official} (Census place {geoid})'
        alternate = official if official != name else ''
        extra = '\t' + label if label else ''
        lines.append(f'{geoid}\t{state}\t{name}\t{alternate}\t{lat:.4f}\t{lon:.4f}{extra}\n'.encode())
    shards = [[]]
    size = 0
    for line in lines:
        if size + len(line) > 600_000:
            shards.append([])
            size = 0
        shards[-1].append(line)
        size += len(line)
    for old in out.glob('us-places-*.tsv'):
        old.unlink()
    for index, shard in enumerate(shards, 1):
        (out / f'us-places-{index:02}.tsv').write_bytes(b''.join(shard))
    print(f'Generated {len(lines)} places in {len(shards)} shards.')


if __name__ == '__main__':
    main()
