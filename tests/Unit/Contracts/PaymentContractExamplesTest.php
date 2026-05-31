<?php

namespace Tests\Unit\Contracts;

use Tests\TestCase;

class PaymentContractExamplesTest extends TestCase
{
    private static function contractsRoot(): string
    {
        return dirname(__DIR__, 3).'/contracts';
    }

    /**
     * @return list<string>
     */
    public static function exampleFilesProvider(): array
    {
        $files = glob(self::contractsRoot().'/examples/payment.*.v1.json') ?: [];

        return array_map(
            static fn (string $file): array => [basename($file)],
            $files,
        );
    }

    /**
     * @return list<string>
     */
    public static function schemaFilesProvider(): array
    {
        $files = glob(self::contractsRoot().'/messages/payment.*.v1.json') ?: [];

        return array_map(
            static fn (string $file): array => [basename($file)],
            $files,
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('exampleFilesProvider')]
    public function test_example_files_have_required_headers_and_payload(string $filename): void
    {
        $contents = json_decode(
            file_get_contents(self::contractsRoot().'/examples/'.$filename),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertArrayHasKey('headers', $contents);
        $this->assertArrayHasKey('payload', $contents);

        foreach ([
            'message_id',
            'correlation_id',
            'causation_id',
            'idempotency_key',
            'schema_version',
            'occurred_at',
            'producer',
        ] as $header) {
            $this->assertArrayHasKey($header, $contents['headers'], $filename);
        }

        $this->assertSame('v1', $contents['headers']['schema_version']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('schemaFilesProvider')]
    public function test_schema_files_are_valid_json_schema_documents(string $filename): void
    {
        $contents = json_decode(
            file_get_contents(self::contractsRoot().'/messages/'.$filename),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $contents['$schema']);
        $this->assertArrayHasKey('title', $contents);
        $this->assertArrayHasKey('type', $contents);
    }

    public function test_asyncapi_contract_exists_and_lists_all_messages(): void
    {
        $yaml = file_get_contents(self::contractsRoot().'/asyncapi.yaml');

        $this->assertNotFalse($yaml);

        foreach ([
            'payment.authorization.requested.v1',
            'payment.authorization.approved.v1',
            'payment.authorization.declined.v1',
            'payment.capture.requested.v1',
            'payment.capture.completed.v1',
            'payment.capture.failed.v1',
            'payment.refund.requested.v1',
            'payment.refund.completed.v1',
            'payment.refund.failed.v1',
        ] as $message) {
            $this->assertStringContainsString($message, $yaml);
        }
    }
}
