<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Secrets::class)]
#[Group('AC-WP-011')]
#[Group('AC-WP-020')]
class SecretsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['rfq_test_options'] = [];
    }

    public function test_encrypt_and_decrypt_round_trip(): void
    {
        RFQ_Secrets::ensure_encryption_key();

        $encrypted = RFQ_Secrets::encrypt('supabase-secret-key');

        $this->assertNotSame('supabase-secret-key', $encrypted);
        $this->assertSame('supabase-secret-key', RFQ_Secrets::decrypt($encrypted));
    }

    public function test_get_and_set_secret_helpers(): void
    {
        RFQ_Secrets::ensure_encryption_key();
        RFQ_Secrets::set_secret('rfq_s3_secret_key', 'stored-secret');

        $this->assertTrue(RFQ_Secrets::has_secret('rfq_s3_secret_key'));
        $this->assertSame('stored-secret', RFQ_Secrets::get_secret('rfq_s3_secret_key'));
    }

    public function test_ensure_encryption_key_is_idempotent(): void
    {
        RFQ_Secrets::ensure_encryption_key();
        $first = get_option(RFQ_Secrets::ENCRYPTION_KEY_OPTION);

        RFQ_Secrets::ensure_encryption_key();

        $this->assertSame($first, get_option(RFQ_Secrets::ENCRYPTION_KEY_OPTION));
    }

    public function test_encrypted_blob_is_not_plaintext(): void
    {
        RFQ_Secrets::ensure_encryption_key();
        RFQ_Secrets::set_secret('rfq_jwt_secret', 'jwt-signing-secret');

        $stored = get_option('rfq_jwt_secret');

        $this->assertIsString($stored);
        $this->assertStringNotContainsString('jwt-signing-secret', $stored);
    }
}
