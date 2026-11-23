<?php
/*
 * CitiSense admin — "Forgot password" for administrators.
 *
 * Reuses the residents' recovery in the mobile app's API, so there is one
 * implementation of the code emails and their checks:
 *   1. forgot_password.php  emails a 6-digit code (valid 5 minutes, at most
 *                           5 per hour, 1 per minute; stored only as a hash
 *                           in password_reset_otps)
 *   2. verify_otp.php       checks the code (max. 5 tries) and returns a
 *                           one-time reset key (stored only as a SHA-256 hash)
 *   3. the new password is saved HERE, with the stricter admin password rules
 *      (partial/passwords.php), and every reset record of the account is
 *      deleted so neither the code nor the key can be used again.
 *
 * The admin site first checks that the email belongs to an ADMIN account and
 * only then asks the API to send a code. The page always shows the same
 * message, so nobody can find out which emails are registered.
 *
 * Configuration (admin service environment variables):
 *   CS_API_URL   address of the mobile API, e.g.
 *                https://citisense-api-production.up.railway.app/
 *                (if missing, CS_MEDIA_FALLBACK_URL is used, which normally
 *                points to the same server)
 * The email account itself is configured on the API service (Brevo, Gmail …).
 */

if (!function_exists("csApiBaseUrl")) {

    /** Base address of the mobile API with a trailing slash, or "". */
    function csApiBaseUrl(): string
    {
        $url = trim((string) getenv("CS_API_URL"));
        if ($url === "") {
            $url = trim((string) getenv("CS_MEDIA_FALLBACK_URL"));
        }
        return $url !== "" ? rtrim($url, "/") . "/" : "";
    }

    /**
     * POST form fields to an API endpoint.
     * Returns ["status" => HTTP code (0 = could not connect), "data" => decoded JSON or null].
     */
    function csApiPost(string $endpoint, array $fields): array
    {
        $base = csApiBaseUrl();
        if ($base === "") {
            return ["status" => 0, "data" => null, "error" => "CS_API_URL is not set."];
        }

        $url = $base . ltrim($endpoint, "/");
        $body = http_build_query($fields);

        if (function_exists("curl_init")) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => ["Accept: application/json"],
            ]);
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
        } else {
            $context = stream_context_create(["http" => [
                "method" => "POST",
                "header" => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                "content" => $body,
                "timeout" => 30,
                "ignore_errors" => true,
            ]]);
            $response = @file_get_contents($url, false, $context);
            $status = 0;
            foreach ($http_response_header ?? [] as $header) {
                if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $m)) {
                    $status = (int) $m[1];
                }
            }
            $error = $response === false ? "Connection failed." : "";
        }

        $data = is_string($response) ? json_decode($response, true) : null;

        return ["status" => $status, "data" => is_array($data) ? $data : null, "error" => $error];
    }
}
