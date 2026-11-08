# Local canvas libraries

The browser bundles in this directory come from pinned upstream npm releases. The accompanying `.LICENSE` files retain the upstream license notices.

| Bundle | Package and version | Browser global | Bytes | SHA-256 |
| --- | --- | --- | ---: | --- |
| `color-thief.js` | `colorthief@3.5.0` | `ColorThief` | 22,281 | `f4a8c69a9df43f598d72279d69b59f2d9d29561a34fe99b117492dbc908163ee` |
| `d3.js` | `d3@7.9.2` | `d3` | 280,622 | `912111098ea99503a6f4967361d2bbdc9dbf58cf9c678c8e27e5b02d1a07f0c1` |
| `matter.js` | `matter-js@0.20.0` | `Matter` | 84,834 | `961faf813d745b243f016800013fbc8ed75ed02c393bc2ca9bc0123270200b46` |
| `three.js` | `three@0.187.0` | `THREE` | 749,788 | `c78895acdff5f794696fc6840893006b8640f1c4adf97fcdc7bc8891aa6d9d06` |

## Reproduce the bundles

Run these commands from a scratch directory on Linux x64. The commands verify the source archives before extracting them. Color Thief uses the published browser global build with its source map reference removed. D3 uses the published minified UMD bundle byte for byte. Matter.js uses the published browser build with the local property merge and path guards below. Three.js bundles the WebGL package entry into a single classic script using esbuild 0.25.12. The source archive URLs also identify the exact npm releases. [Color Thief](https://github.com/lokesh/color-thief) · [D3](https://d3js.org/getting-started) · [Matter.js](https://github.com/liabru/matter-js) · [Three.js](https://github.com/mrdoob/three.js) · [esbuild](https://github.com/evanw/esbuild)

```sh
set -eu
curl -fLsS https://registry.npmjs.org/colorthief/-/colorthief-3.5.0.tgz -o colorthief.tgz
curl -fLsS https://registry.npmjs.org/d3/-/d3-7.9.2.tgz -o d3.tgz
curl -fLsS https://registry.npmjs.org/matter-js/-/matter-js-0.20.0.tgz -o matter.tgz
curl -fLsS https://registry.npmjs.org/three/-/three-0.187.0.tgz -o three.tgz
curl -fLsS https://registry.npmjs.org/@esbuild/linux-x64/-/linux-x64-0.25.12.tgz -o esbuild.tgz
sha256sum -c <<'SUMS'
9f10d792d8dc1402e4ce24a26d7eb5ae0669db41415f03003f0fa1f02ebfa8fc  colorthief.tgz
f0c1d2c2479907a6e3265370d0fd715f7aef0f561cdd2b415df385ba7df9d1aa  d3.tgz
c2594c16b67adf76d32d9c4576a388a32cee80e5dfe2823cd3bf1b8fb93cc7f2  matter.tgz
ff540832ded0cfe919764d24a3d88f3e2a05f23303f876423e4f893841e90728  three.tgz
f7efc127658a108dcda9d4210b01804ee8f5d6b3acbd6d63f20aa51d3b50bd5f  esbuild.tgz
SUMS
mkdir -p colorthief d3 matter three esbuild
tar -xzf colorthief.tgz -C colorthief
tar -xzf d3.tgz -C d3
tar -xzf matter.tgz -C matter
tar -xzf three.tgz -C three
tar -xzf esbuild.tgz -C esbuild
sed '/^\/\/# sourceMappingURL=/d' colorthief/package/dist/umd/color-thief.global.js > color-thief.js
cp d3/package/dist/d3.min.js d3.js
python3 - matter/package/build/matter.min.js matter.js <<'PYTHON'
from pathlib import Path
import hashlib
import sys

source = Path(sys.argv[1]).read_bytes()
assert hashlib.sha256(source).hexdigest() == '72d30be0f579eb02ce1e0b6f9d359a4f392e6837e5a26ba8be5dbee7f88e24ae'
source = source.decode('utf-8')
extend_start = source.index(',n.extend=function(')
extend_end = source.index(',n.clone=function(', extend_start)
set_start = source.index('n.set=function(', extend_end)
set_end = source.index(',n.shuffle=function(', set_start)

source = source[:set_start] + '''n.set=function(object,path,value,begin,end){
    var parts=path.split("."), key=parts.slice(begin,end).pop();
    if (key===undefined || !parts.every(safeMatterKey)) {
        throw new TypeError("Invalid Matter.js property path.");
    }
    for (var index=0; index<parts.length-1; index++) {
        if (!ownMatterProperty(object,parts[index])) {
            throw new TypeError("Invalid Matter.js property path.");
        }
        object=object[parts[index]];
    }
    object[key]=value;
    return value;
}''' + source[set_end:]

source = source[:extend_start] + ''';
/* Stonefellow canvas hardening for upstream issue #1351. */
function safeMatterKey(key) {
    return key!=="__proto__" && key!=="constructor" && key!=="prototype";
}
function ownMatterProperty(object,key) {
    return Object.prototype.hasOwnProperty.call(object,key);
}
function plainMatterObject(value) {
    return value && typeof value==="object" && Object.getPrototypeOf(value)===Object.prototype;
}
n.extend=function(object,deep){
    var hasDepth=typeof deep==="boolean", deepClone=hasDepth ? deep : true;
    for (var index=hasDepth ? 2 : 1; index<arguments.length; index++) {
        var source=arguments[index];
        if (!source) continue;
        for (var key in source) {
            if (!ownMatterProperty(source,key) || !safeMatterKey(key)) continue;
            var value=source[key];
            var previous=ownMatterProperty(object,key) ? object[key] : undefined;
            if (deepClone && plainMatterObject(value) && (!previous || plainMatterObject(previous))) {
                var target=previous || {};
                object[key]=target;
                n.extend(target,deepClone,value);
            } else {
                object[key]=value;
            }
        }
    }
    return object;
}''' + source[extend_end:]
Path(sys.argv[2]).write_bytes(source.encode('utf-8'))
PYTHON
./esbuild/package/bin/esbuild three/package/build/three.module.js \
  --bundle --platform=browser --format=iife --global-name=THREE \
  --target=safari15,chrome90,firefox90 --minify --legal-comments=inline --outfile=three.js
cp colorthief/package/LICENSE color-thief.LICENSE
cp d3/package/LICENSE d3.UPSTREAM-LICENSE
cp matter/package/LICENSE matter.LICENSE
cp three/package/LICENSE three.LICENSE
sha256sum -c <<'SUMS'
f4a8c69a9df43f598d72279d69b59f2d9d29561a34fe99b117492dbc908163ee  color-thief.js
912111098ea99503a6f4967361d2bbdc9dbf58cf9c678c8e27e5b02d1a07f0c1  d3.js
961faf813d745b243f016800013fbc8ed75ed02c393bc2ca9bc0123270200b46  matter.js
c78895acdff5f794696fc6840893006b8640f1c4adf97fcdc7bc8891aa6d9d06  three.js
SUMS
```

The D3 npm provenance identifies source commit `db118c93a429e82b83f091dbaa18fd0a82c06130`. The upstream ISC text is the first section of `d3.LICENSE`, followed by the notices for the embedded components. Component versions come from the published unminified bundle and the corresponding upstream lockfile. [D3 source and lockfile](https://github.com/d3/d3/tree/db118c93a429e82b83f091dbaa18fd0a82c06130)

D3's array, scale, shape, geographic projection, and DOM/SVG APIs work with data passed to the canvas. The sandbox blocks network loaders and dynamic JavaScript evaluation. Use `d3.csvParseRows` or `d3.tsvParseRows` for local delimited text. The upstream object parsers `d3.csvParse`, `d3.tsvParse`, and `d3.dsvFormat(delimiter).parse` require dynamic evaluation. [D3 DSV](https://d3js.org/d3-dsv)

The Matter.js npm release declares zero runtime dependencies. The registry signature and tarball integrity verified, and its unmodified browser build matches source commit `acb99b6f8784c809b940f1d2cf745427e088e088` byte for byte. The local guards address prototype pollution through `Common.extend` and `Common.set`. Merges skip inherited source keys and reserved prototype keys and recurse only into own target objects. The setter rejects reserved path segments and inherited traversal before writing. [Matter.js source](https://github.com/liabru/matter-js/tree/acb99b6f8784c809b940f1d2cf745427e088e088) · [Upstream report](https://github.com/liabru/matter-js/issues/1351)
