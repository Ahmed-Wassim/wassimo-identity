<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateJwtKeys extends Command
{
    protected $signature = 'jwt:keys {--force : overwrite existing keys}';

    protected $description = 'Generate the Ed25519 keypair used to sign access JWTs';

    public function handle(): int
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->error('ext-sodium is missing. Fallback with openssl:');
            $this->line('  openssl genpkey -algorithm ed25519 -out private.pem');
            $this->line('  openssl pkey -in private.pem -pubout -out public.pem');
            $this->line('Then base64url-encode the DER bytes of each file into the key files below.');
            return self::FAILURE;
        }

        $dir = storage_path('app/jwt');
        $privatePath = $dir.'/private.key';
        $publicPath = $dir.'/public.key';

        if (! $this->option('force') && (is_file($privatePath) || is_file($publicPath))) {
            $this->error('Keys already exist. Re-run with --force to rotate (invalidates all live access JWTs).');
            return self::FAILURE;
        }

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $keypair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keypair); // 64 bytes: seed + public
        $public = sodium_crypto_sign_publickey($keypair); // 32 bytes

        // firebase/php-jwt EdDSA takes the last non-empty line and base64url-decodes
        // it, so raw bytes in that encoding are the whole file format. No PEM
        // armor: armor invites alg-confusion-style parsing bugs, raw bytes do not.
        file_put_contents($privatePath, rtrim(sodium_bin2base64($secret, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)).PHP_EOL);
        file_put_contents($publicPath, rtrim(sodium_bin2base64($public, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)).PHP_EOL);
        chmod($privatePath, 0600);

        sodium_memzero($keypair);

        $this->info("Private: {$privatePath} (0600, never commit, never ship in images)");
        $this->info("Public:  {$publicPath} (copy to the gateway as JWT_PUBLIC_KEY)");

        return self::SUCCESS;
    }
}
