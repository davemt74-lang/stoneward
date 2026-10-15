# Section 86D — Verified native-state decoding, existing Stem Studio

## Where it lives
Admin → Catalog → Song → Stem Studio. No second plugin engine, uploader,
catalog or HomeServer dependency. The imported .rpp remains private and
byte-for-byte intact.

## Exact parameter decoding versus inspection
- **Verified**: The built-in `loser/masterLimiter` JSFX source's *seven* slider
  positions. Exactly six persist as parameters; slider7 is a reduction meter.
  Native values are threshold -8.3 dB, look-ahead 200 ns, attack 100 ns,
  hold 0 ms, release 250 ms and limit/ceiling -0.1 dB in the user's Luna
  .rpp. This mapping is sourced from the original JSFX slider declarations:
  https://raw.githubusercontent.com/Samelot/Reaper/master/Effects/LOSER/masterLimiter
- **Browser mapping**: threshold, ceiling, attack and release are converted
  to the already existing limiter's *approximation*. Lookahead and hold have
  no equivalent in that graph, so remain shown as exact but unsupported.
  Audio is **NOT** sample-identical to REAPER.
- **Observed format**: ReaEQ, ReaComp, ReaVerb and ReaDelay Windows DLLs have
  observed VST base64 header FourCC + container versions in the user's RPP.
  Those are verified for identity and corruption only, never treated as a
  proven serialization format for EQ bands, compression ratios, delay times,
  convolution, or filter controls. Parameter confidence remains
  `container-only`; no numeric VST controls are claimed native-exact.

## Backfill and safe migration
The existing Admin plugin rack exposes original JSFX values alongside the
browser settings. Press **Verify native REAPER parameters** to re-parse an
already-imported *private* RPP (no new upload required). It verifies the
stored SHA-256 and FX GUID/native-state hash before upgrading provenance.
It never resets saved browser overrides, media assignments, private
ownership, mix history, original plugin chain order or native RPP bytes.
Every action is revision-locked to prevent stale writes.

## Acceptance / limits
86D isolated PHP tests, 86C/86B/86A/85A compatibility, whole site PHP/JS
and MySQL release gate, exact merged-main source, deploy ZIP with SHA-256.
The Luna project is used only for private local acceptance; no raw user
RPP or audio goes into GitHub. Do not advertise exact ReaPlugs numeric
parameter reconstruction or exact sound without schema-specific validated
decoders and listening tests. Post-deployment HostGator acceptance remains
separate from source CI.

## Next stage after 86D is merged and fully green
86E: build externally validated version-specific ReaPlugs VST knob schemas,
and prove them with known-source input/output RPP fixture round-trips;
only then add editable imported EQ/Comp/Reverb/Delay native numbers. Keep
aux/group bus routing as a separate gated section.
