<?php
declare(strict_types=1);

$START_TIME = microtime(true);

/**
 * img.php — on-the-fly image resizer (PHP 8.3 + Imagick 3.7)
 *
 * Query string contract:
 *   ?url=/images/products/AF-N.jpg   (required) web path of source image, resolved under BASE_DIR
 *   &x=400                           target WIDTH in px  (optional)
 *   &y=400                           target HEIGHT in px (optional)
 *   &q=10                            quality 0..10  ->  0%..100%  (JPEG/WebP only, optional)
 *
 * Rules:
 *   - x only  -> scale to width x, keep aspect.
 *   - y only  -> scale to height y, keep aspect.
 *   - x and y -> fit INSIDE the x*y box, keep aspect (no crop, no distortion).
 *   - neither -> original size.
 *   - Upscaling is allowed (request larger than source enlarges the image).
 *   - Output format always mirrors the source (JPEG, PNG, WebP).
 */

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

/** Fixed sandbox root. The url param is resolved UNDER this directory. Edit to your asset root. */
const BASE_DIR = '/var/www/images';

/** Directory where processed images are cached. */
const CACHE_DIR = BASE_DIR . '/cache';

/** Hard upper bound on any requested dimension, to avoid memory blowups. */
const MAX_DIM = 10000;

/** Requests slower than this (milliseconds) are recorded in the log file. */
const SLOW_MS = 100;

/** Log file for slow requests. Lives next to this script. */
const LOG_FILE = __DIR__ . '/img.log';

/** Allowed source extensions -> output MIME type. Quality honored for jpeg/webp only. */
const ALLOWED = [
	'jpg'  => 'image/jpeg',
	'jpeg' => 'image/jpeg',
	'png'  => 'image/png',
	'webp' => 'image/webp',
];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Emit an error status and a plain-text body, then stop. */
function fail(int $code, string $msg): never
{
	http_response_code($code);
	header('Content-Type: text/plain; charset=utf-8');
	echo $msg;
	exit;
}

/**
 * If the request has taken longer than SLOW_MS, append one line to the log file.
 * Format: ISO-8601 time, elapsed ms, source path, requested params.
 */
function logSlow(string $path): void
{
	global $START_TIME;

	$elapsedMs = (microtime(true) - $START_TIME) * 1000;
	if ($elapsedMs <= SLOW_MS) {
		return;
	}

	$line = sprintf(
		"%s\t%.1fms\t%s\tx=%s y=%s q=%s\n",
		date('c'),
		$elapsedMs,
		$path,
		$_GET['x'] ?? '-',
		$_GET['y'] ?? '-',
		$_GET['q'] ?? '-'
	);
	@file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

/** Parse an optional positive integer dimension from the query string. */
function dim(string $key): ?int
{
	if (!isset($_GET[$key]) || $_GET[$key] === '') {
		return null;
	}
	$v = filter_var($_GET[$key], FILTER_VALIDATE_INT);
	if ($v === false || $v <= 0 || $v > MAX_DIM) {
		fail(400, "Invalid '$key' parameter.");
	}
	return $v;
}

// ---------------------------------------------------------------------------
// 1. Preflight
// ---------------------------------------------------------------------------

if (!extension_loaded('imagick')) {
	fail(500, 'Imagick extension is not loaded.');
}

// ---------------------------------------------------------------------------
// 2. Resolve & sandbox the source path
// ---------------------------------------------------------------------------

$url = $_GET['url'] ?? '';
if (!is_string($url) || $url === '') {
	fail(400, "Missing 'url' parameter.");
}

// Drop any query/fragment a caller may have appended, then percent-decode.
$url = preg_replace('/[?#].*$/', '', $url);
$url = rawurldecode($url);

if (str_contains($url, "\0")) {
	fail(400, 'Malformed path.');
}

$baseReal = realpath(BASE_DIR);
if ($baseReal === false) {
	fail(500, 'Base directory is not configured correctly.');
}

$candidate = $baseReal . DIRECTORY_SEPARATOR . ltrim(str_replace('\\', '/', $url), '/');
$real      = realpath($candidate);

// Traversal guard: file must exist AND live strictly inside the sandbox root.
if ($real === false
	|| !is_file($real)
	|| !str_starts_with($real, $baseReal . DIRECTORY_SEPARATOR)
) {
	fail(404, 'Not found.');
}

// ---------------------------------------------------------------------------
// 3. Validate format
// ---------------------------------------------------------------------------

$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
if (!isset(ALLOWED[$ext])) {
	fail(415, 'Unsupported media type.');
}
$mime         = ALLOWED[$ext];
$qualityFormat = in_array($mime, ['image/jpeg', 'image/webp'], true);

// ---------------------------------------------------------------------------
// 4. Parse sizing & quality params
// ---------------------------------------------------------------------------

$x = dim('x');
$y = dim('y');

$quality = null;
if (isset($_GET['q']) && $_GET['q'] !== '') {
	$q = filter_var($_GET['q'], FILTER_VALIDATE_INT);
	if ($q === false || $q < 0 || $q > 10) {
		fail(400, "Invalid 'q' parameter (expected 0..10).");
	}
	$quality = $q * 10; // 0..10 -> 0..100
}

// ---------------------------------------------------------------------------
// 5. Caching: serve 304 when the client already has the current file.
//    The representation depends on the file mtime AND the transform params.
// ---------------------------------------------------------------------------

$mtime = filemtime($real);
$etag  = '"' . md5($real . '|' . $mtime . "|x=$x|y=$y|q=$quality") . '"';

header('Cache-Control: public, max-age=86400');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('ETag: ' . $etag);

$ifNoneMatch = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
$ifModSince  = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '') ?: 0;
if (($ifNoneMatch !== '' && $ifNoneMatch === $etag)
	|| ($ifModSince !== 0 && $ifModSince >= $mtime)
) {
	http_response_code(304);
	exit;
}

// ---------------------------------------------------------------------------
// 6. Fast path: no resize and no re-encode -> stream original bytes verbatim.
// ---------------------------------------------------------------------------

if ($x === null && $y === null && $quality === null) {
	header('Content-Type: ' . $mime);
	header('Content-Length: ' . filesize($real));
	readfile($real);
	logSlow($real);
	exit;
}

// ---------------------------------------------------------------------------
// 7. Server-side disk cache for the processed (resized/re-encoded) image.
//    Key = base64(query string) + source mtime (local ISO, digits only) + size.
//    The base64 is made filename-safe (base64url, padding stripped) so it is a
//    single valid filename on disk.
// ---------------------------------------------------------------------------

$queryString = $_SERVER['QUERY_STRING'] ?? '';
$modDigits   = preg_replace('/\D/', '', date('c', $mtime)); // local time, ISO, digits only
$size        = filesize($real);

// $cacheKey identifies this exact request (url + x/y/q); the ".<mtime><size>"
// suffix identifies the source-file version. A '.' delimiter (not in the
// base64url alphabet) lets us match all versions of one request unambiguously.
$cacheKey  = str_replace('=', '', strtr(base64_encode($queryString), '+/', '-_'));
$cacheFile = $cacheKey . '.' . $modDigits . $size;
$cachePath = CACHE_DIR . '/' . $cacheFile;

// Cache hit -> serve the stored bytes with the correct mime type.
if (is_file($cachePath)) {
	header('Content-Type: ' . $mime);
	header('Content-Length: ' . filesize($cachePath));
	readfile($cachePath);
	logSlow($real);
	exit;
}

// ---------------------------------------------------------------------------
// 8. Cache miss: load, resize, (re)encode with Imagick, then store + serve.
// ---------------------------------------------------------------------------

try {
	$im = new Imagick($real);
	$im->setFirstIterator(); // operate on the first frame for multi-frame inputs

	$srcW = $im->getImageWidth();
	$srcH = $im->getImageHeight();

	if ($srcW > 0 && $srcH > 0) {
		$targetW = null;
		$targetH = null;

		if ($x !== null && $y !== null) {
			// Fit inside the x*y box, preserving aspect (upscaling allowed).
			$scale   = min($x / $srcW, $y / $srcH);
			$targetW = max(1, (int) round($srcW * $scale));
			$targetH = max(1, (int) round($srcH * $scale));
		} elseif ($x !== null) {
			$targetW = $x;
			$targetH = max(1, (int) round($srcH * ($x / $srcW)));
		} elseif ($y !== null) {
			$targetH = $y;
			$targetW = max(1, (int) round($srcW * ($y / $srcH)));
		}

		if ($targetW !== null && ($targetW !== $srcW || $targetH !== $srcH)) {
			$im->resizeImage($targetW, $targetH, Imagick::FILTER_LANCZOS, 1);
		}
	}

	if ($quality !== null && $qualityFormat) {
		$im->setImageCompressionQuality($quality);
	}

	$blob = $im->getImageBlob();

	// Save to cache (best effort — never let a cache write break delivery).
	if (!is_dir(CACHE_DIR)) {
		@mkdir(CACHE_DIR, 0775, true);
	}

	// The source file changed since it was last cached (different mtime/size),
	// so remove any stale version(s) of this same request before saving the new one.
	foreach (glob(CACHE_DIR . '/' . $cacheKey . '.*') ?: [] as $stale) {
		if ($stale !== $cachePath) {
			@unlink($stale);
		}
	}

	@file_put_contents($cachePath, $blob, LOCK_EX);

	header('Content-Type: ' . $mime);
	header('Content-Length: ' . strlen($blob));
	echo $blob;
	logSlow($real);
} catch (Throwable $e) {
	fail(500, 'Image processing failed.');
} finally {
	if (isset($im) && $im instanceof Imagick) {
		$im->clear();
		$im->destroy();
	}
}
