# Section 90Z — Upload → Choose Uses → Publish

Release: Stonefellow v1.3.159.

## Primary path

Admin → Catalog → **Upload Song** now opens a single form for the audio file,
song credits, ownership confirmation and uses. A metadata template is optional.
Selecting **Publish to streaming player** writes the catalog entry after private
intake and explicit rights confirmation. Leaving streaming unchecked saves a
private draft and does not create public listening links or digital/vinyl offers.

The Media Library's Add to Catalog form also supports the same template-free
private intake and publishing writer. Bulk folder scan remains an advanced tool.

## Protected masters on shared hosts

- A source previously designated `master_audio` is never itself made public.
- If FFmpeg is installed, Stonefellow transcodes a distinct 128 kbps MP3.
- Without FFmpeg, an existing MP3 is safely remuxed into a separate listening
  asset: private ID3v2 and ID3v1 tags are stripped, a minimal identifying tag
  is inserted, and audio frames are copied without changing their bitrate.
- WAV masters without FFmpeg still require an administrator-supplied listening
  MP3. This limitation is displayed in the review rather than turning an
  original master into a publicly accessible file.
- The publisher verifies source ownership and checksums and rejects any public
  link to a protected master. Public player assets are never downloadable as
  private originals. Paid downloads/vinyl require a separate private original.

## Verification

- JS syntax and Catalog DOM/source regression assertions.
- PHP integration: real encoded MP3 with private ID3 tags, distinct listening
  asset, no source-tag leak, protected-master link, paid/vinyl flags, public
  listener link and idempotent publication.
- Existing 90T/90V1/90U suite compatibility, PHP lint, and release-gate ZIP.

## Live-site acceptance (HostGator)

1. Upload one new MP3 via Catalog → Upload Song, choose streaming, and Publish.
2. Open the track and listen in the public player; verify visibility from a
   signed-out browser.
3. Upload an MP3 that already has a legacy private-master role; Publish should
   prepare a separate copy even when FFmpeg is absent.
4. With streaming unchecked, save the file privately and verify that it is not
   in the public player.
5. Select downloads or vinyl; verify the source still cannot be fetched from
   the public media API and all enabled use flags match the Admin selection.
6. Revisit and retry a failed draft without creating a duplicate track.
