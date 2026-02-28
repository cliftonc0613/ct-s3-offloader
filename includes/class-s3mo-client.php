<?php
/**
 * S3MO_Client — AWS S3 client wrapper.
 *
 * Provides a simplified interface to the AWS S3 SDK for bucket operations,
 * connection testing, and URL generation (with optional CloudFront CDN).
 *
 * @package CT_S3_Offloader
 */

defined('ABSPATH') || exit;

use Aws\S3\S3Client;
use Aws\S3\ObjectUploader;
use Aws\Exception\AwsException;

class S3MO_Client {

    private S3Client $s3;
    private string $bucket;
    private string $region;

    /**
     * Construct the S3 client from wp-config.php constants.
     */
    public function __construct() {
        $this->bucket = S3MO_BUCKET;
        $this->region = S3MO_REGION;

        $this->s3 = new S3Client([
            'version'     => 'latest',
            'region'      => $this->region,
            'credentials' => [
                'key'    => S3MO_KEY,
                'secret' => S3MO_SECRET,
            ],
        ]);
    }

    /**
     * Test the connection to the configured S3 bucket.
     *
     * @return array{success: bool, message: string, code?: string}
     */
    public function test_connection(): array {
        try {
            $this->s3->headBucket(['Bucket' => $this->bucket]);
            return [
                'success' => true,
                'message' => 'Connected to bucket: ' . $this->bucket,
            ];
        } catch (AwsException $e) {
            $code     = $e->getAwsErrorCode();
            $messages = [
                'NoSuchBucket'          => 'Bucket "' . $this->bucket . '" does not exist.',
                'InvalidAccessKeyId'    => 'Invalid AWS Access Key ID.',
                'SignatureDoesNotMatch' => 'Invalid AWS Secret Access Key.',
                'AccessDenied'          => 'Access denied. Check IAM policy permissions.',
                '403'                   => 'Forbidden. Check IAM user permissions for this bucket.',
            ];

            $message = $messages[$code] ?? $e->getAwsErrorMessage() ?? $e->getMessage();

            return [
                'success' => false,
                'message' => $message,
                'code'    => $code,
            ];
        }
    }

    /**
     * Get the underlying S3Client instance.
     */
    public function get_s3_client(): S3Client {
        return $this->s3;
    }

    /**
     * Get the configured bucket name.
     */
    public function get_bucket(): string {
        return $this->bucket;
    }

    /**
     * Get the configured AWS region.
     */
    public function get_region(): string {
        return $this->region;
    }

    /**
     * Get the base URL for serving files.
     *
     * Prefers S3MO_CDN_URL (CloudFront) if defined, otherwise falls back
     * to the direct S3 bucket URL.
     */
    public function get_url_base(): string {
        if (defined('S3MO_CDN_URL') && ! empty(S3MO_CDN_URL)) {
            return rtrim(S3MO_CDN_URL, '/');
        }

        return "https://{$this->bucket}.s3.{$this->region}.amazonaws.com";
    }

    /**
     * Upload a file to S3 using ObjectUploader for automatic multipart handling.
     *
     * ACL is set to 'private' — CloudFront OAC handles public access (CDN-04).
     * CacheControl is set for long-lived immutable caching (CDN-02).
     *
     * @param string $key          The S3 object key (path within the bucket).
     * @param string $file_path    Absolute path to the local file.
     * @param string $content_type MIME type for the Content-Type header.
     *
     * @return array{success: bool, key: string, url?: string, error?: string}
     */
    public function upload_object(string $key, string $file_path, string $content_type): array {
        $body = fopen($file_path, 'rb');

        if ($body === false) {
            throw new \RuntimeException("Cannot open file for reading: {$file_path}");
        }

        try {
            $uploader = new ObjectUploader(
                $this->s3,
                $this->bucket,
                $key,
                $body,
                'private',
                [
                    'params' => [
                        'ContentType'  => $content_type,
                        'CacheControl' => 'public, max-age=31536000, immutable',
                    ],
                ]
            );

            $uploader->upload();

            return [
                'success' => true,
                'key'     => $key,
                'url'     => $this->get_object_url($key),
            ];
        } catch (AwsException $e) {
            return [
                'success' => false,
                'key'     => $key,
                'error'   => $e->getAwsErrorMessage() ?? $e->getMessage(),
            ];
        } finally {
            fclose($body);
        }
    }

    /**
     * Delete an object from S3 by key.
     *
     * @param string $key The S3 object key to delete.
     *
     * @return array{success: bool, key: string, error?: string}
     */
    public function delete_object(string $key): array {
        try {
            $this->s3->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => $key,
            ]);

            return [
                'success' => true,
                'key'     => $key,
            ];
        } catch (AwsException $e) {
            return [
                'success' => false,
                'key'     => $key,
                'error'   => $e->getAwsErrorMessage() ?? $e->getMessage(),
            ];
        }
    }

    /**
     * Generate the public URL for an S3 object key.
     *
     * Delegates to get_url_base() which prefers CloudFront when configured.
     *
     * @param string $key The S3 object key.
     *
     * @return string Full URL to the object.
     */
    public function get_object_url(string $key): string {
        return $this->get_url_base() . '/' . ltrim($key, '/');
    }
}
