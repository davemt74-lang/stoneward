#!/usr/bin/env python3
"""Build the Canvas Data map layer from the Census 2025 state 1:20m KML ZIP."""

import argparse
import json
import re
from itertools import groupby
from pathlib import Path
from xml.etree import ElementTree
from zipfile import ZipFile

KML = {'k': 'http://www.opengis.net/kml/2.2'}
MAX_ROWS = 120
MAX_RESPONSE_BYTES = 256 * 1024
VERTICES_PER_CHUNK = 160


def compact(value):
    return json.dumps(value, ensure_ascii=False, allow_nan=False, separators=(',', ':'))


def read_regions(source):
    with ZipFile(source) as archive:
        document = ElementTree.fromstring(archive.read('cb_2025_us_state_20m.kml'))
    regions = {}
    for place in document.findall('.//k:Placemark', KML):
        attrs = {attr.get('name'): attr.text for attr in place.findall('.//k:SimpleData', KML)}
        region, name = attrs['STUSPS'], attrs['NAME']
        if not re.fullmatch(r'[A-Z]{2}', region) or not name or region in regions:
            raise ValueError('Invalid or duplicate Census region.')
        polygons = []
        for polygon in place.findall('.//k:Polygon', KML):
            outer = polygon.findall('k:outerBoundaryIs/k:LinearRing/k:coordinates', KML)
            holes = polygon.findall('k:innerBoundaryIs/k:LinearRing/k:coordinates', KML)
            if len(outer) != 1:
                raise ValueError(f'Missing exterior ring for {region}.')
            rings = []
            for element in outer + holes:
                points = [tuple(map(float, item.split(',')[:2])) for item in element.text.split()]
                if len(points) < 4 or points[0] != points[-1] or any(
                    not (-180 <= lon <= 180 and -90 <= lat <= 90) for lon, lat in points
                ):
                    raise ValueError(f'Invalid boundary ring for {region}.')
                rings.append(points)
            polygons.append(rings)
        if not polygons:
            raise ValueError(f'No boundary polygons for {region}.')
        regions[region] = name, polygons
    if len(regions) != 52 or not {'AK', 'DC', 'HI', 'PR'} <= regions.keys():
        raise ValueError('Expected the 50 U.S. states, District of Columbia, and Puerto Rico.')
    return regions


def polygon_rows(region, name, part, rings, precision):
    rows = []
    for ring, points in enumerate(rings):
        rounded = (tuple(round(value, precision) for value in point) for point in points)
        vertices = [point for point, _ in groupby(rounded)]
        if len(set(vertices)) < 3:
            raise ValueError(f'Boundary ring collapsed at selected precision for {region}.')
        for offset in range(0, len(vertices), VERTICES_PER_CHUNK):
            coordinates = compact(vertices[offset:offset + VERTICES_PER_CHUNK])
            if len(coordinates) > 4000:
                raise ValueError('Boundary coordinate chunk exceeds the Canvas Data field limit.')
            rows.append(dict(region=region, name=name, part=part, ring=ring,
                             chunk=offset // VERTICES_PER_CHUNK, coordinates=coordinates))
    return rows


def ring_area(points):
    return abs(sum(x * next_y - next_x * y for (x, y), (next_x, next_y) in zip(points, points[1:])))


def map_layers(regions):
    regional, overview, extras = {}, [], []
    for region, (name, polygons) in sorted(regions.items()):
        detailed = []
        overview_parts = []
        for part, rings in enumerate(polygons):
            detailed.extend(polygon_rows(region, name, part, rings, 5))
            coarse = polygon_rows(region, name, part, rings, 3)
            overview_parts.append((ring_area(rings[0]), coarse))
        regional[region] = detailed
        overview_parts.sort(key=lambda item: item[0], reverse=True)
        overview.extend(overview_parts[0][1])
        extras.extend(overview_parts[1:])
    # Keep every region's principal polygon before filling the remaining budget
    # with the largest available islands and other disconnected polygons.
    for _, rows in sorted(extras, key=lambda item: item[0], reverse=True):
        candidate = overview + rows
        if len(candidate) <= MAX_ROWS and len(compact(candidate).encode()) < MAX_RESPONSE_BYTES:
            overview = candidate
    overview.sort(key=lambda row: (row['region'], row['part'], row['ring'], row['chunk']))
    for rows in [*regional.values(), overview]:
        if len(rows) > MAX_ROWS or len(compact(rows).encode()) >= MAX_RESPONSE_BYTES:
            raise ValueError('A boundary layer exceeds the Canvas Data payload limit.')
    return regional, overview


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('census_kml_zip', type=Path)
    args = parser.parse_args()
    regional, overview = map_layers(read_regions(args.census_kml_zip))
    target = Path(__file__).resolve().parents[1] / 'config' / 'canvas-maps'
    target.mkdir(parents=True, exist_ok=True)
    region_lines = [compact(region) + ':' + compact(rows) for region, rows in regional.items()]
    (target / 'us-regions.json').write_text('{\n' + ',\n'.join(region_lines) + '\n}\n', encoding='utf-8')
    (target / 'us-overview.json').write_text('[\n' + ',\n'.join(map(compact, overview)) + '\n]\n',
                                            encoding='utf-8')
    print(f'Generated {len(regional)} regions and {len(overview)} overview rows.')


if __name__ == '__main__':
    main()
