<?php
/*
 * CitiSense admin — small shared view helpers.
 * Loaded by partial/head.php; safe to include more than once.
 */

/*
 * WHERE THE MOBILE APP'S PHOTOS ARE
 * ---------------------------------------------------------------
 * The database stores photo paths such as "uploads/reports/REP001.jpg".
 * Those files are saved by the mobile app's API, which may live in a
 * different folder than this admin website (for example
 * htdocs/citisense_api/uploads/reports/).
 *
 * The admin site finds them automatically. For each photo it looks in:
 *   1. this project folder (CITISENSE/),
 *   2. CITISENSE/api/,
 *   3. any folder you list in CS_MEDIA_ROOTS below,
 *   4. every other project folder in htdocs that has an "uploads" folder
 *      (one and two levels deep, e.g. htdocs/x/uploads, htdocs/x/api/uploads),
 *   5. CITISENSE/admin/ (where Settings saves admin profile pictures).
 * Admin > Database Check (admin/db_check.php) shows where photos were found.
 *
 * Only if the photos are on ANOTHER computer or a hosted server, set
 * CS_MEDIA_BASE_URL to that folder's web address (keep the slash at the end):
 *     define("CS_MEDIA_BASE_URL", "http://192.168.1.10/citisense_api/");
 */
if (!defined("CS_MEDIA_ROOTS")) {
    // Extra folders on this computer that contain the "uploads" folder, e.g.
    // ["C:/xampp/htdocs/citisense_api"]
    define("CS_MEDIA_ROOTS", []);
}

if (!defined("CS_MEDIA_BASE_URL")) {
    define("CS_MEDIA_BASE_URL", "../");
}

if (!function_exists("csMediaRoots")) {

    /** Folders (absolute, with trailing slash) that may contain uploaded photos. */
    function csMediaRoots(): array
    {
        static $roots = null;

        if ($roots !== null) {
            return $roots;
        }

        $project = dirname(__DIR__, 2);
        $htdocs = dirname($project);
        $list = [$project, $project . "/api"];

        foreach ((array) CS_MEDIA_ROOTS as $extra) {
            $list[] = (string) $extra;
        }

        // Other projects in htdocs that have an uploads folder.
        $found = array_merge(
            glob($htdocs . "/uploads", GLOB_ONLYDIR) ?: [],
            glob($htdocs . "/*/uploads", GLOB_ONLYDIR) ?: [],
            glob($htdocs . "/*/*/uploads", GLOB_ONLYDIR) ?: []
        );
        $others = array_map("dirname", $found);
        // Folders whose name mentions CitiSense are tried first.
        usort($others, static fn($a, $b) => (int) (stripos($b, "citi") !== false) <=> (int) (stripos($a, "citi") !== false));
        $list = array_merge($list, $others);
        $list[] = $project . "/admin"; // admin profile pictures (Settings) are saved here

        $roots = [];
        foreach ($list as $dir) {
            $real = $dir !== "" ? realpath($dir) : false;
            if ($real !== false && is_dir($real)) {
                $roots[str_replace("\\", "/", $real) . "/"] = true;
            }
        }

        return $roots = array_keys($roots);
    }

    /** Clean a stored relative path; "" when it is unsafe or not relative. */
    function csCleanMediaPath($path): string
    {
        $path = str_replace("\\", "/", trim((string) ($path ?? "")));
        $path = ltrim(preg_replace('~^(\./)+~', "", $path), "/");

        if ($path === "" || str_contains($path, "..") || str_contains($path, "\0") || preg_match('~^[a-z][a-z0-9+.-]*:~i', $path)) {
            return "";
        }

        return $path;
    }

    /** Absolute file for a stored photo path, searched in csMediaRoots(); "" when not found. */
    function csFindMediaFile($path): string
    {
        static $cache = [];
        $path = csCleanMediaPath($path);

        if ($path === "") {
            return "";
        }

        if (array_key_exists($path, $cache)) {
            return $cache[$path];
        }

        foreach (csMediaRoots() as $root) {
            $file = $root . $path;
            if (is_file($file)) {
                return $cache[$path] = $file;
            }
        }

        return $cache[$path] = "";
    }
}

if (!function_exists("csEscape")) {

    /**
     * Turn a stored photo path into a working URL for admin pages.
     * Full URLs and site-absolute paths are used as they are.
     */
    function csMediaUrl($path): string
    {
        $path = trim((string) ($path ?? ""));

        if ($path === "") {
            return "";
        }

        if (preg_match('~^(https?:)?//|^/|^data:~i', $path)) {
            return $path;
        }

        $clean = csCleanMediaPath($path);

        // A custom address was set: photos are on another server.
        if (CS_MEDIA_BASE_URL !== "../" || $clean === "") {
            return CS_MEDIA_BASE_URL . ltrim(preg_replace('~^(\./)+~', "", $path), "/");
        }

        $file = csFindMediaFile($clean);
        $project = str_replace("\\", "/", (string) realpath(dirname(__DIR__, 2))) . "/";

        // Not found anywhere: keep the normal link (the page shows "Photo not available").
        if ($file === "") {
            return "../" . $clean;
        }

        // Inside this project folder: a normal link to where the file really is.
        $file = str_replace("\\", "/", $file);
        if (str_starts_with($file, $project)) {
            return "../" . implode("/", array_map("rawurlencode", explode("/", substr($file, strlen($project)))));
        }

        // Found in another folder (e.g. the mobile API): served by media.php.
        return "media.php?p=" . rawurlencode($clean);
    }

    /** Escape any value for HTML output. */
    function csEscape($value): string
    {
        return htmlspecialchars(
            (string) ($value ?? ""),
            ENT_QUOTES | ENT_SUBSTITUTE,
            "UTF-8"
        );
    }

    /** "In Progress" -> "in-progress" (used for badge/tone classes). */
    function csStatusSlug($statusName): string
    {
        return trim(
            preg_replace("/[^a-z0-9]+/", "-", strtolower((string) $statusName)),
            "-"
        );
    }

    /** Bootstrap icon that accompanies each status label. */
    function csStatusIcon($statusName): string
    {
        switch (csStatusSlug($statusName)) {
            case "pending":
                return "bi-hourglass-split";
            case "in-progress":
                return "bi-tools";
            case "resolved":
                return "bi-check-circle-fill";
            case "overdue":
                return "bi-exclamation-triangle-fill";
            case "rejected":
                return "bi-x-circle-fill";
            default:
                return "bi-circle";
        }
    }

    /**
     * Status badge: colour + icon + the written status, so status is never
     * communicated by colour alone.
     */
    function csStatusBadge($statusName, string $label = ""): string
    {
        $statusName = trim((string) $statusName);
        $slug = csStatusSlug($statusName);
        $known = ["pending", "in-progress", "resolved", "overdue", "rejected"];
        $class = in_array($slug, $known, true) ? "cs-badge-" . $slug : "cs-badge-neutral";

        return '<span class="cs-badge has-icon ' . $class . '">'
            . '<i class="bi ' . csStatusIcon($statusName) . '" aria-hidden="true"></i>'
            . csEscape($label !== "" ? $label : ($statusName !== "" ? $statusName : "Unknown"))
            . '</span>';
    }

    /** Format a database date/time, or return $fallback. */
    function csDate($value, string $format = "M j, Y", string $fallback = ""): string
    {
        if (!$value) {
            return $fallback;
        }

        $stamp = strtotime((string) $value);

        return $stamp === false ? $fallback : date($format, $stamp);
    }

    /** "3 hours ago" style relative time for recent dates. */
    function csTimeAgo($value): string
    {
        $stamp = $value ? strtotime((string) $value) : false;

        if ($stamp === false) {
            return "";
        }

        $seconds = time() - $stamp;

        if ($seconds < 0) {
            return date("M j, Y", $stamp);
        }

        if ($seconds < 60) {
            return "Just now";
        }
        if ($seconds < 3600) {
            $m = (int) floor($seconds / 60);
            return $m . ($m === 1 ? " minute ago" : " minutes ago");
        }
        if ($seconds < 86400) {
            $h = (int) floor($seconds / 3600);
            return $h . ($h === 1 ? " hour ago" : " hours ago");
        }
        if ($seconds < 86400 * 30) {
            $d = (int) floor($seconds / 86400);
            return $d . ($d === 1 ? " day ago" : " days ago");
        }

        return date("M j, Y", $stamp);
    }

    /** Build a URL to $page keeping the given query values (empty ones dropped). */
    function csUrl(string $page, array $query = []): string
    {
        $query = array_filter(
            $query,
            static fn($value) => $value !== "" && $value !== null
        );

        return $page . ($query ? "?" . http_build_query($query) : "");
    }

    /** Bootstrap icon per report category name. */
    function csCategoryIcon($categoryName): string
    {
        $name = strtolower((string) $categoryName);

        if (str_contains($name, "waste") || str_contains($name, "garbage")) {
            return "bi-trash3";
        }
        if (str_contains($name, "pothole") || str_contains($name, "road")) {
            return "bi-cone-striped";
        }
        if (str_contains($name, "drain") || str_contains($name, "flood")) {
            return "bi-droplet-half";
        }

        return "bi-three-dots";
    }
}
