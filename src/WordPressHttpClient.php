<?php

namespace Zion\WordPressLicense;

use RuntimeException;

final class WordPressHttpClient
{
    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function post(string $url, array $payload, array $headers = []): array
    {
        if (function_exists('wp_remote_post')) {
            $response = wp_remote_post($url, ['timeout' => 10, 'headers' => array_merge(['Accept' => 'application/json'], $headers), 'body' => $payload]);
            if (is_wp_error($response)) {
                throw new RuntimeException($response->get_error_message());
            }

            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            $statusCode = (int) wp_remote_retrieve_response_code($response);
            if ($statusCode >= 400 || ! is_array($body)) {
                $requestId = (string) wp_remote_retrieve_header($response, 'x-request-id');
                $reference = $requestId !== '' ? " Request ID: {$requestId}." : '';

                throw new RuntimeException("License server rejected the request (HTTP {$statusCode}).{$reference}");
            }

            return $body;
        }

        throw new RuntimeException('WordPress HTTP API is not available.');
    }
}
