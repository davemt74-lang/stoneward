# Local canvas libraries

The browser bundles in this directory come from pinned upstream npm releases. The accompanying `.LICENSE` files retain the upstream MIT notices.

| Bundle | Package and version | Browser global | Bytes | SHA-256 |
| --- | --- | --- | ---: | --- |
| `color-thief.js` | `colorthief@3.5.0` | `ColorThief` | 22,281 | `f4a8c69a9df43f598d72279d69b59f2d9d29561a34fe99b117492dbc908163ee` |
| `three.js` | `three@0.187.0` | `THREE` | 749,788 | `c78895acdff5f794696fc6840893006b8640f1c4adf97fcdc7bc8891aa6d9d06` |

## Reproduce the bundles

Run these commands from a scratch directory on Linux x64. The commands verify the source archives before extracting them. Color Thief uses the published browser global build with its source map reference removed. Three.js bundles the WebGL package entry into a single classic script using esbuild 0.25.12. The source archive URLs also identify the exact npm releases. [Color Thief](https://github.com/lokesh/color-thief) · [Three.js](https://github.com/mrdoob/three.js) · [esbuild](https://github.com/evanw/esbuild)

```sh
set -eu
curl -fLsS https://registry.npmjs.org/colorthief/-/colorthief-3.5.0.tgz -o colorthief.tgz
curl -fLsS https://registry.npmjs.org/three/-/three-0.187.0.tgz -o three.tgz
curl -fLsS https://registry.npmjs.org/@esbuild/linux-x64/-/linux-x64-0.25.12.tgz -o esbuild.tgz
sha256sum -c <<'SUMS'
9f10d792d8dc1402e4ce24a26d7eb5ae0669db41415f03003f0fa1f02ebfa8fc  colorthief.tgz
ff540832ded0cfe919764d24a3d88f3e2a05f23303f876423e4f893841e90728  three.tgz
f7efc127658a108dcda9d4210b01804ee8f5d6b3acbd6d63f20aa51d3b50bd5f  esbuild.tgz
SUMS
mkdir -p colorthief three esbuild
tar -xzf colorthief.tgz -C colorthief
tar -xzf three.tgz -C three
tar -xzf esbuild.tgz -C esbuild
sed '/^\/\/# sourceMappingURL=/d' colorthief/package/dist/umd/color-thief.global.js > color-thief.js
./esbuild/package/bin/esbuild three/package/build/three.module.js \
  --bundle --platform=browser --format=iife --global-name=THREE \
  --target=safari15,chrome90,firefox90 --minify --legal-comments=inline --outfile=three.js
cp colorthief/package/LICENSE color-thief.LICENSE
cp three/package/LICENSE three.LICENSE
sha256sum -c <<'SUMS'
f4a8c69a9df43f598d72279d69b59f2d9d29561a34fe99b117492dbc908163ee  color-thief.js
c78895acdff5f794696fc6840893006b8640f1c4adf97fcdc7bc8891aa6d9d06  three.js
SUMS
```
