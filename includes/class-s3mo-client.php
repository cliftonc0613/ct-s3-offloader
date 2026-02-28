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
}
