<?php

namespace Zion\WordPressLicense;

use Zion\WordPressLicense\Exceptions\ApiException;
use Zion\WordPressLicense\Exceptions\ServerUnavailableException;
use RuntimeException;

final class WordPressHttpClient
{
    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function post(string $url, array $payload, array $headers = []): array
    {
        if (function_exists('wp_remote_post')) {
            $requestId = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(16));
            $requestHeaders = array_merge([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Request-Id' => $requestId,
                Protocol::HEADER => Protocol::VERSION,
            ], $headers);
            $encodedPayload = wp_json_encode($payload);
            if (! is_string($encodedPayload)) {
                throw new RuntimeException('The license request could not be encoded.');
            }

            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $response = wp_remote_post($url, [
                    'timeout' => 10,
                    'sslverify' => true,
                    'headers' => $requestHeaders,
                    'body' => $encodedPayload,
                ]);

                if (is_wp_error($response)) {
                    if ($attempt < 3) {
                        usleep(250000 * $attempt);

                        continue;
                    }

                    throw new ServerUnavailableException('The license server could not be reached.', $requestId);
                }

                $statusCode = (int) wp_remote_retrieve_response_code($response);
                if (($statusCode === 429 || $statusCode >= 500) && $attempt < 3) {
                    usleep(250000 * $attempt);

                    continue;
                }

                $body = json_decode((string) wp_remote_retrieve_body($response), true);
                if ($statusCode >= 400 || ! is_array($body)) {
                    $responseId = (string) wp_remote_retrieve_header($response, 'x-request-id');
                    $reference = $responseId !== '' ? " Request ID: {$responseId}." : '';
                    $error = is_array($body['error'] ?? null) ? $body['error'] : [];
                    $errorCode = is_string($error['code'] ?? null) ? $error['code'] : 'api_error';
                    $errorMessage = is_string($error['message'] ?? null) ? $error['message'] : "License server rejected the request (HTTP {$statusCode}).";

                    throw new ApiException(
                        $errorMessage.$reference,
                        $errorCode,
                        $statusCode,
                        $responseId !== '' ? $responseId : $requestId,
                        is_array($error['details'] ?? null) ? $error['details'] : [],
                    );
                }

                return $body;
            }
        }

        throw new RuntimeException('WordPress HTTP API is not available.');
    }
}
