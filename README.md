# s3-filemanager

Single-page PHP application to browse and manage **AWS S3** buckets and objects. It also supports **S3-compatible** providers (custom endpoint and path-style addressing).

## Requirements

- **PHP** 8.1 or newer with extensions used by the AWS SDK (including `curl`, `json`, `simplexml`, `libxml`, `openssl`)
- [Composer](https://getcomposer.org/) for dependencies
- A web server (Apache, nginx, PHP built-in server, etc.) with HTTPS recommended in production

## Install

```bash
composer install
```

Point the document root (or your vhost) at this directory so `s3filemanager.php` is reachable. Example with PHP’s built-in server:

```bash
php -S localhost:8080
```

Then open `http://localhost:8080/s3filemanager.php` in your browser.

## First-time setup

1. **Change the login password**  
   Edit `$auth_users` in `s3filemanager.php`. Generate a bcrypt hash:

   ```bash
   php -r "echo password_hash('your-secure-password', PASSWORD_DEFAULT), PHP_EOL;"
   ```

   Replace the sample hash with the output. Remove or replace the default `admin` user in production.

2. **Sign in** at the login page, then enter your **AWS access key**, **secret key**, **region**, and optionally **custom endpoint** (for MinIO, Hetzner Object Storage, etc.) and **path-style** if your provider requires it.

3. **Secret key field**  
   After credentials are saved, the secret field can be left blank on “Save” to keep the existing secret while updating other fields.

## Features

- List / create / delete buckets  
  - **Delete bucket** empties the bucket first: deletes all object versions and delete markers (when `ListObjectVersions` is supported), otherwise falls back to listing current keys; then **aborts incomplete multipart uploads**, then deletes the bucket.  
  - **Create bucket** omits `LocationConstraint` for **us-east-1** on default AWS endpoints (required by AWS); other regions and custom endpoints still send the configured region.
- Tree and folder views, lazy loading via `?tree=1`
- Upload files, create folders, delete files and folders (prefix delete)
- **Rename folder** (copy all objects under a prefix to a new prefix, then delete the old prefix)
- Bulk delete; **bulk rename** (prefix/suffix applies to the **file name only**, keeping parent folders); bulk move with normalized target folder paths
- Download proxy (`?download=1`) with `Content-Disposition` filename, and short-lived presigned GET URLs (`?presign=1`)
- Optional JSON bucket size: `?bucket_size=1&bucket=…`

## Security notes

- Treat this app as **admin-only**: it stores S3 keys in the PHP session and can delete data.
- Use **HTTPS** in production. Session cookies are set with `HttpOnly`, `SameSite=Lax`, and `Secure` when the request is detected as HTTPS.
- Prefer **IAM roles** or short-lived credentials on servers you control instead of long-lived keys in the UI where possible.

## License

MIT — see [LICENSE](LICENSE).
