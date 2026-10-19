# Scaling: Object Store

With `STORAGE_DRIVER=s3` the workspace files live in an S3-compatible object store instead of the data volume. A
test-taker's request for a resource file (player, unit definition, ...) is answered by the backend with a redirect
(`302`) to a presigned URL, and the browser downloads the file directly from the object store. This page describes
how the backend handles these requests and the one setting that matters for many simultaneous test-takers.

## How a resource request is answered

1. The group token of the request is checked.
2. The requested name is turned into an object key. Only the known sub-folders of a workspace are accepted, and names
   with empty, `.` or `..` segments, a leading or trailing `/`, a backslash or a NUL byte are rejected with `404`.
   This is the same containment rule as for files on the data volume, but it needs no access to the object store, so
   it runs before any lookup.
3. With the presigned-URL cache switched on, a cached URL for the key is returned right away.
4. Otherwise the backend asks the object store whether the object exists (`404` if not), signs a URL for it and, with
   the cache on, stores the URL.

The redirect carries `Cache-Control: private`, like every other file response, so shared caches (e.g. a CDN) do not
pass it on without the token check.

### Uncompressed downloads

Presigned URLs ask the object store to deliver the stored bytes unchanged (`response-content-encoding=identity`, part
of the signature). Some object stores, e.g. STACKIT's, otherwise compress each download on the fly whenever the
browser accepts gzip, which every browser does. Such a response is chunked, has no `Content-Length`, and its duration
is unpredictable: in a load test one 43.8 MB download took more than 20 minutes compressed, against 5 to 11 seconds
uncompressed. Nothing has to be configured for this.

## Presigned-URL cache

Signing a URL is a local computation, but the existence check before it is a round trip to the object store which
holds a PHP worker for its whole duration (about 50 ms in our measurements, most of it a TLS handshake). All
test-takers of a workspace load the same few files, so at the start of a large exam the backend makes the same check
hundreds of thousands of times. The cache stores the signed URL per object key in the cache service (Redis), so the
check happens once per file and URL lifetime.

The cache is switched off by default.

### Configuration

| docker compose (`.env.prod`) | Helm value | Default | Meaning |
|---|---|---|---|
| `TESTCENTER_CACHE_PRESIGNED_URLS` | `objectStore.cachePresignedUrls` | `false` | switches the cache on (`true`, `on`, `yes` or `1`) |
| `S3_PRESIGN_TTL` | `objectStore.presignTtlSeconds` | `3600` | lifetime of a presigned URL in seconds |

An entry is kept 300 seconds shorter than the URL it holds is valid, so a URL handed out at the end of its entry
still has 300 seconds left when the browser follows the redirect. With `S3_PRESIGN_TTL` of 300 or less the cache stays
off.

The cache uses the existing cache service (`REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`). Without a configured cache
service the switch has no effect. It is independent of the auth-token cache.

### When to enable it

Enable it for large exams on the object store, where many test-takers start at the same time. In a load test with
about 100,000 test-takers, the existence checks of the resource requests (about 700,000 during the ramp-up) occupied
all PHP workers and caused about 10,300 `503` responses; with the cache, seven cache entries replaced them and the
next run finished without errors.

With few simultaneous test-takers the round trip does not matter and the cache can stay off.

### Invalidation and consistency

- A file replaced under the same name needs no invalidation: the signature covers the key, not the content, so a
  cached URL delivers the new version.
- Deleting files in a workspace drops all cached URLs. Without that, the browser would follow a cached URL to the
  object store and get its `404` instead of the backend's.
- The cached URLs are signed with the configured access key. After rotating the object-store credentials, restart the
  cache service (or wait `S3_PRESIGN_TTL` minus 300 seconds); until then cached URLs signed with the old key are
  rejected by the object store.

### Failure behaviour

The cache is an optimisation; it never decides whether a request may proceed. The group token and the file name are
checked before the cache is consulted. If the cache service cannot be reached, or a cache command fails, the backend
checks and signs as if the cache were switched off.

### Sizing

One entry is one URL of a few hundred bytes per file that test-takers request, so the memory needed is negligible.
