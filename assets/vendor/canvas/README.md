# Local canvas libraries

The browser bundles in this directory come from pinned upstream npm releases. The accompanying `.LICENSE` files retain the upstream license notices.

| Bundle | Package and version | Browser global | Bytes | SHA-256 |
| --- | --- | --- | ---: | --- |
| `color-thief.js` | `colorthief@3.5.0` | `ColorThief` | 22,281 | `f4a8c69a9df43f598d72279d69b59f2d9d29561a34fe99b117492dbc908163ee` |
| `d3.js` | `d3@7.9.2` | `d3` | 280,622 | `912111098ea99503a6f4967361d2bbdc9dbf58cf9c678c8e27e5b02d1a07f0c1` |
| `three.js` | `three@0.187.0` | `THREE` | 749,788 | `c78895acdff5f794696fc6840893006b8640f1c4adf97fcdc7bc8891aa6d9d06` |

## Reproduce the bundles

Run these commands from a scratch directory on Linux x64. The commands verify the source archives before extracting them. Color Thief uses the published browser global build with its source map reference removed. D3 uses the published minified UMD bundle byte for byte. Three.js bundles the WebGL package entry into a single classic script using esbuild 0.25.12. The source archive URLs also identify the exact npm releases. [Color Thief](https://github.com/lokesh/color-thief) · [D3](https://d3js.org/getting-started) · [Three.js](https://github.com/mrdoob/three.js) · [esbuild](https://github.com/evanw/esbuild)

```sh
set -eu
curl -fLsS https://registry.npmjs.org/colorthief/-/colorthief-3.5.0.tgz -o colorthief.tgz
curl -fLsS https://registry.npmjs.org/d3/-/d3-7.9.2.tgz -o d3.tgz
curl -fLsS https://registry.npmjs.org/three/-/three-0.187.0.tgz -o three.tgz
curl -fLsS https://registry.npmjs.org/@esbuild/linux-x64/-/linux-x64-0.25.12.tgz -o esbuild.tgz
sha256sum -c <<'SUMS'
9f10d792d8dc1402e4ce24a26d7eb5ae0669db41415f03003f0fa1f02ebfa8fc  colorthief.tgz
f0c1d2c2479907a6e3265370d0fd715f7aef0f561cdd2b415df385ba7df9d1aa  d3.tgz
ff540832ded0cfe919764d24a3d88f3e2a05f23303f876423e4f893841e90728  three.tgz
f7efc127658a108dcda9d4210b01804ee8f5d6b3acbd6d63f20aa51d3b50bd5f  esbuild.tgz
SUMS
mkdir -p colorthief d3 three esbuild
tar -xzf colorthief.tgz -C colorthief
tar -xzf d3.tgz -C d3
tar -xzf three.tgz -C three
tar -xzf esbuild.tgz -C esbuild
sed '/^\/\/# sourceMappingURL=/d' colorthief/package/dist/umd/color-thief.global.js > color-thief.js
cp d3/package/dist/d3.min.js d3.js
./esbuild/package/bin/esbuild three/package/build/three.module.js \
  --bundle --platform=browser --format=iife --global-name=THREE \
  --target=safari15,chrome90,firefox90 --minify --legal-comments=inline --outfile=three.js
cp colorthief/package/LICENSE color-thief.LICENSE
cp d3/package/LICENSE d3.UPSTREAM-LICENSE
cp three/package/LICENSE three.LICENSE
sha256sum -c <<'SUMS'
f4a8c69a9df43f598d72279d69b59f2d9d29561a34fe99b117492dbc908163ee  color-thief.js
912111098ea99503a6f4967361d2bbdc9dbf58cf9c678c8e27e5b02d1a07f0c1  d3.js
c78895acdff5f794696fc6840893006b8640f1c4adf97fcdc7bc8891aa6d9d06  three.js
SUMS
```

The D3 npm provenance identifies source commit `db118c93a429e82b83f091dbaa18fd0a82c06130`. The upstream ISC text is the first section of `d3.LICENSE`, followed by the notices for the embedded components. Component versions come from the published unminified bundle and the corresponding upstream lockfile. [D3 source and lockfile](https://github.com/d3/d3/tree/db118c93a429e82b83f091dbaa18fd0a82c06130)

D3's array, scale, shape, geographic projection, and DOM/SVG APIs work with data passed to the canvas. The sandbox blocks network loaders and dynamic JavaScript evaluation. Use `d3.csvParseRows` or `d3.tsvParseRows` for local delimited text. The upstream object parsers `d3.csvParse`, `d3.tsvParse`, and `d3.dsvFormat(delimiter).parse` require dynamic evaluation. [D3 DSV](https://d3js.org/d3-dsv)
