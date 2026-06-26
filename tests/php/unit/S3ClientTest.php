<?php

declare(strict_types=1);

use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_S3_Client::class)]
#[Group('AC-WP-024')]
class S3ClientTest extends TestCase
{
    private const SECRET = 'unit-test-s3-secret-value';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['rfq_test_options'] = [];
        $GLOBALS['rfq_test_filters'] = [];
        RFQ_Secrets::ensure_encryption_key();
    }

    public function test_from_settings_returns_error_when_not_configured(): void
    {
        $client = RFQ_S3_Client::from_settings();

        $this->assertInstanceOf(WP_Error::class, $client);
        $this->assertSame('rfq_s3_not_configured', $client->get_error_code());
        $this->assertSame(503, $client->get_error_data()['status']);
    }

    public function test_from_settings_builds_client_with_path_style_endpoint(): void
    {
        $this->configure_s3_settings();

        $client = RFQ_S3_Client::from_settings();

        $this->assertInstanceOf(RFQ_S3_Client::class, $client);
        $this->assertSame('rfq-test-bucket', $client->get_bucket_name());
    }

    public function test_put_json_writes_json_object(): void
    {
        $client = $this->create_mocked_aws_client([
            new Result(['ETag' => '"abc"']),
        ]);

        $result = $client->put_json('intake/session/meta/draft.json', ['contact' => ['email' => 'a@example.com']]);

        $this->assertTrue($result);
    }

    public function test_head_object_returns_true_when_object_exists(): void
    {
        $client = $this->create_mocked_aws_client([
            new Result(['ContentLength' => 10]),
        ]);

        $this->assertTrue($client->head_object('intake/session/parts/file.step'));
    }

    public function test_head_object_returns_false_for_missing_object(): void
    {
        $mock = new MockHandler();
        $mock->append(function (): void {
            throw new Aws\Exception\AwsException(
                'Not Found',
                new Command('HeadObject'),
                ['response' => new GuzzleHttp\Psr7\Response(404)]
            );
        });

        $client = $this->create_mocked_aws_client([], $mock);

        $this->assertFalse($client->head_object('missing.key'));
    }

    public function test_head_object_returns_false_for_nosuchkey_with_non_404_status(): void
    {
        $mock = new MockHandler();
        $mock->append(function (): void {
            throw new Aws\Exception\AwsException(
                'Bad Request',
                new Command('HeadObject'),
                [
                    'response' => new GuzzleHttp\Psr7\Response(400),
                    'code' => 'NoSuchKey',
                ]
            );
        });

        $client = $this->create_mocked_aws_client([], $mock);

        $this->assertFalse($client->head_object('missing.key'));
    }

    public function test_create_presigned_put_binds_key_content_type_and_default_expiry(): void
    {
        $client = $this->create_mocked_aws_client([]);

        $url = $client->create_presigned_put(
            'intake/session/parts/uuid_bracket.step',
            'application/octet-stream',
            524288000
        );

        $this->assertIsString($url);
        $this->assertStringContainsString('intake/session/parts/uuid_bracket.step', $url);
        $this->assertStringContainsString('X-Amz-Expires=1800', $url);
        $this->assertStringContainsString('x-amz-meta-rfq-max-bytes=524288000', $url);
    }

    public function test_create_presigned_put_rejects_non_positive_expiry(): void
    {
        $client = $this->create_mocked_aws_client([]);

        $result = $client->create_presigned_put(
            'intake/session/drawings/uuid.pdf',
            'application/pdf',
            52428800,
            0
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('rfq_s3_invalid_expiry', $result->get_error_code());
    }

    public function test_aws_errors_do_not_leak_secret_material(): void
    {
        $this->configure_s3_settings();

        $mock = new MockHandler();
        $mock->append(function (): void {
            throw new Aws\Exception\AwsException(
                'The request signature we calculated does not match the signature you provided. Check your key and signing method. Secret=' . self::SECRET,
                new Command('PutObject'),
                ['response' => new GuzzleHttp\Psr7\Response(403)]
            );
        });

        $client = $this->create_mocked_aws_client([], $mock);
        $error = $client->put_json('intake/session/meta/draft.json', ['ok' => true]);

        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertStringNotContainsString(self::SECRET, $error->get_error_message());
        $this->assertSame('rfq_s3_error', $error->get_error_code());
    }

    public function test_resolve_uses_filter_for_mock_adapter(): void
    {
        $mock = new RFQ_S3_Client_Mock('filtered-bucket');

        add_filter('rfq_s3_client', static fn () => $mock);

        $resolved = RFQ_S3_Client::resolve();

        $this->assertSame($mock, $resolved);
    }

    /**
     * @param list<Result|callable> $queue
     */
    private function create_mocked_aws_client(array $queue, ?MockHandler $handler = null): RFQ_S3_Client
    {
        $this->configure_s3_settings();

        $mock_handler = $handler ?? new MockHandler($queue);
        $aws_client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => 'https://example.test',
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => 'test-access-key',
                'secret' => self::SECRET,
            ],
            'handler' => $mock_handler,
        ]);

        return new RFQ_S3_Client($aws_client, 'rfq-test-bucket');
    }

    private function configure_s3_settings(): void
    {
        update_option('rfq_s3_endpoint', 'https://example.test');
        update_option('rfq_s3_bucket', 'rfq-test-bucket');
        update_option('rfq_s3_access_key_id', 'test-access-key');
        update_option('rfq_s3_region', 'us-east-1');
        RFQ_Secrets::set_secret('rfq_s3_secret_key', self::SECRET);
    }
}
