# PHP Image Server

A single-file, on-the-fly image resizing endpoint for PHP 8.3 + Imagick 3.7.

`img.php` reads an image from disk based on a web path in the query string, optionally
resizes it and/or re-encodes JPEG/WebP at a chosen quality, caches the result, and streams
it back with the correct `Content-Type`.

## Requirements

- PHP 8.3
- The **Imagick** extension (3.7) enabled in your web SAPI. The script returns `500` if it
  is not loaded.

## Usage

Request `img.php` with a query string:

```
/img.php?url=/images/products/AF-N.jpg&x=400&q=8
```

### Parameters

| Param | Required | Meaning |
|-------|----------|---------|
| `url` | **yes**  | Web path of the source image, resolved **under `BASE_DIR`**. |
| `x`   | no       | Target **width** in pixels (1–`MAX_DIM`). |
| `y`   | no       | Target **height** in pixels (1–`MAX_DIM`). |
| `q`   | no       | Quality `0`–`10` → `0%`–`100%`. **JPEG/WebP only**; ignored for PNG. |

### Resizing rules

- `x` only → scale to width `x`, preserving aspect ratio.
- `y` only → scale to height `y`, preserving aspect ratio.
- `x` **and** `y` → fit *inside* the `x`×`y` box, preserving aspect ratio (no crop, no distortion).
- neither → original dimensions.
- **Upscaling is allowed**: requesting a size larger than the source enlarges the image (Lanczos filter).
- Output format always **mirrors the source** (JPEG, PNG, or WebP). No format conversion.

### Examples

```
/img.php?url=/images/products/AF-N.jpg            # original, served verbatim
/img.php?url=/images/products/AF-N.jpg&y=400      # 400px tall
/img.php?url=/images/products/AF-N.jpg&x=400      # 400px wide
/img.php?url=/images/products/AF-N.jpg&x=400&y=400 # fit inside 400×400
/img.php?url=/images/products/AF-N.jpg&x=800&q=6  # 800px wide, JPEG quality 60%
```

## Configuration

Constants at the top of `img.php`:

| Constant     | Default                              | Purpose |
|--------------|--------------------------------------|---------|
| `BASE_DIR`   | `C:/Source/oddStuff/imgSrv/public`   | Sandbox root; all `url` paths resolve under it. **Set this to your asset root.** |
| `MAX_DIM`    | `10000`                              | Hard cap on any requested dimension (guards against memory blowups). |
| `SLOW_MS`    | `100`                                | Requests slower than this are logged. |
| `LOG_FILE`   | `<script dir>/img.log`               | Slow-request log file. |
| `CACHE_DIR`  | `BASE_DIR/images/cache`              | Where processed images are cached. |
| `ALLOWED`    | jpg, jpeg, png, webp                 | Allowed source extensions → output MIME type. |

## Security

The `url` parameter is sandboxed: it is percent-decoded, NUL-byte checked, resolved with
`realpath()`, and required to live **strictly inside `BASE_DIR`**. This blocks `../`
traversal, symlinks pointing outside the root, and absolute paths — all return `404`.

## Caching

### HTTP caching
Responses send `Cache-Control`, `Last-Modified`, and an `ETag`. Conditional requests
(`If-None-Match` / `If-Modified-Since`) get a `304 Not Modified`.

### Server-side disk cache
Processed (resized/re-encoded) images are cached on disk in `CACHE_DIR`.

- **Cache key** = `<base64url(query string)>.<source mtime, local ISO, digits only><source size in bytes>`.
- **Hit** → the stored bytes are served directly with the correct MIME type (skips Imagick).
- **Miss** → the image is processed, **any stale version of the same request is deleted**,
  and the fresh result is saved.
- Because the key embeds the source file's mtime and size, **modifying the source file
  automatically invalidates and replaces its cache entry**.

The verbatim **fast path** (no `x`/`y`/`q`) streams the original file directly and is not cached.

## Logging

Any request whose total processing time exceeds `SLOW_MS` (default 100 ms) appends one
tab-separated line to `LOG_FILE`:

```
<ISO-8601 time>	<elapsed>ms	<source path>	x=<…> y=<…> q=<…>
```

Cache hits and `304` responses are fast and normally fall below the threshold. Logging is
best-effort and never interferes with image delivery.

## Responses

| Status | When |
|--------|------|
| `200`  | Image served (processed, cached, or verbatim). |
| `304`  | Client's cached copy is still current. |
| `400`  | Missing/invalid `url`, `x`, `y`, or `q`. |
| `404`  | File not found or outside the sandbox. |
| `415`  | Unsupported file type. |
| `500`  | Imagick missing, misconfigured base dir, or processing failure. |
