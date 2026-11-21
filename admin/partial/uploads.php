<?php
/*
 * CitiSense admin — saving uploaded pictures safely.
 *
 * Pictures are saved in the project's uploads/<subfolder>/ folder (next to
 * the mobile app's uploads/reports/) under a random name. The database
 * stores the relative path, e.g. "uploads/announcements/9f1c….jpg", the same
 * way report photos are stored, and admin pages display it with csMediaUrl().
 *
 * Every picture is checked by its real content (not its file name) and, when
 * the GD extension is available, re-saved: this removes hidden data such as
 * the phone's GPS location and shrinks very large photos.
 */

if (!defined("CS_UPLOAD_MAX_BYTES")) {
    define("CS_UPLOAD_MAX_BYTES", 5 * 1024 * 1024);   // 5 MB per picture
    define("CS_UPLOAD_MAX_SIDE", 1600);               // longest side after resizing
}

if (!function_exists("csSaveUploadedImage")) {

    /** Absolute path of the project's uploads/<subfolder>/ directory. */
    function csUploadDir(string $subfolder): string
    {
        return dirname(__DIR__, 2) . "/uploads/" . trim($subfolder, "/") . "/";
    }

    /** Turn a multiple-file $_FILES entry into a list of single files. */
    function csNormalizeFiles(?array $field): array
    {
        if (!$field || !isset($field["name"])) {
            return [];
        }

        if (!is_array($field["name"])) {
            return [$field];
        }

        $files = [];
        foreach (array_keys($field["name"]) as $i) {
            $files[] = [
                "name" => $field["name"][$i],
                "type" => $field["type"][$i] ?? "",
                "tmp_name" => $field["tmp_name"][$i] ?? "",
                "error" => $field["error"][$i] ?? UPLOAD_ERR_NO_FILE,
                "size" => $field["size"][$i] ?? 0,
            ];
        }

        return array_values(array_filter(
            $files,
            static fn($file) => ($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        ));
    }

    /**
     * Check one uploaded picture. Throws RuntimeException with a
     * plain-language message when it cannot be used. Returns its MIME type.
     */
    function csValidateUploadedImage(array $file): string
    {
        $error = (int) ($file["error"] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException("“" . $file["name"] . "” is too large. Please use pictures of 5 MB or smaller.");
        }

        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file["tmp_name"])) {
            throw new RuntimeException("A picture could not be uploaded. Please choose it again.");
        }

        if ((int) $file["size"] > CS_UPLOAD_MAX_BYTES) {
            throw new RuntimeException("“" . $file["name"] . "” is too large. Please use pictures of 5 MB or smaller.");
        }

        $allowed = ["image/jpeg", "image/png", "image/webp"];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file["tmp_name"]);

        if (!in_array($mime, $allowed, true) || @getimagesize((string) $file["tmp_name"]) === false) {
            throw new RuntimeException("“" . $file["name"] . "” is not a JPG, PNG or WEBP picture.");
        }

        return $mime;
    }

    /**
     * Save one validated picture. Returns the relative path to store.
     */
    function csSaveUploadedImage(array $file, string $subfolder): string
    {
        $mime = csValidateUploadedImage($file);
        $directory = csUploadDir($subfolder);

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("The upload folder could not be created. Please check that the uploads folder can be written to.");
        }

        $baseName = bin2hex(random_bytes(16));
        $tmp = (string) $file["tmp_name"];

        /* Re-save with GD when possible (removes hidden data, limits size). */
        if (function_exists("imagecreatefromstring")) {
            $image = @imagecreatefromstring((string) file_get_contents($tmp));

            if ($image !== false) {
                $width = imagesx($image);
                $height = imagesy($image);
                $scale = min(1, CS_UPLOAD_MAX_SIDE / max($width, $height));

                if ($scale < 1) {
                    $newWidth = max(1, (int) round($width * $scale));
                    $newHeight = max(1, (int) round($height * $scale));
                    $resized = imagecreatetruecolor($newWidth, $newHeight);

                    if ($mime === "image/png") {
                        imagealphablending($resized, false);
                        imagesavealpha($resized, true);
                    }

                    imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                    imagedestroy($image);
                    $image = $resized;
                }

                if ($mime === "image/png") {
                    imagesavealpha($image, true);
                    $name = $baseName . ".png";
                    $saved = imagepng($image, $directory . $name, 6);
                } else {
                    $name = $baseName . ".jpg";
                    $saved = imagejpeg($image, $directory . $name, 85);
                }

                imagedestroy($image);

                if ($saved) {
                    return "uploads/" . trim($subfolder, "/") . "/" . $name;
                }
            }
        }

        /* Without GD: keep the original file (already checked above). */
        $extensions = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
        $name = $baseName . "." . $extensions[$mime];

        if (!move_uploaded_file($tmp, $directory . $name)) {
            throw new RuntimeException("A picture could not be saved. Please try again.");
        }

        return "uploads/" . trim($subfolder, "/") . "/" . $name;
    }

    /** Delete a stored picture, but only if it is inside uploads/<subfolder>/. */
    function csDeleteStoredImage(?string $relativePath, string $subfolder): void
    {
        $relativePath = (string) $relativePath;
        $prefix = "uploads/" . trim($subfolder, "/") . "/";

        if ($relativePath === "" || !str_starts_with($relativePath, $prefix)) {
            return;
        }

        $directory = realpath(csUploadDir($subfolder));
        $file = realpath(dirname(__DIR__, 2) . "/" . $relativePath);

        if ($directory && $file && str_starts_with($file, $directory . DIRECTORY_SEPARATOR) && is_file($file)) {
            @unlink($file);
        }
    }
}
