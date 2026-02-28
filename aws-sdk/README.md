# AWS SDK for PHP

This directory contains the AWS SDK for PHP v3, required by CT S3 Offloader.

## Download Instructions

The SDK is not committed to version control due to its size. To install:

```bash
cd /path/to/ct-s3-offloader
curl -L -o aws-sdk.zip https://github.com/aws/aws-sdk-php/releases/latest/download/aws.zip
cd aws-sdk && unzip -o ../aws-sdk.zip && cd ..
rm aws-sdk.zip
```

After extraction, `aws-sdk/aws-autoloader.php` must exist.

## Version

Downloaded from: https://github.com/aws/aws-sdk-php/releases/latest
