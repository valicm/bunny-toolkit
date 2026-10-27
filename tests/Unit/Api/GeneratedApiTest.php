<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Valicm\BunnyToolkit\Api\Core\CoreApi;
use Valicm\BunnyToolkit\Api\Core\Enum\EdgeRuleActionType;
use Valicm\BunnyToolkit\Api\Core\Enum\PatternMatchingTypes;
use Valicm\BunnyToolkit\Api\Core\Enum\TriggerMatchingTypes;
use Valicm\BunnyToolkit\Api\Core\Enum\TriggerTypes;
use Valicm\BunnyToolkit\Api\Core\Resource\PullZone;
use Valicm\BunnyToolkit\Api\Logging\LoggingApi;
use Valicm\BunnyToolkit\Api\Logging\Resource\LegacyLogs;
use Valicm\BunnyToolkit\Api\Logging\Resource\Logs;
use Valicm\BunnyToolkit\Api\MagicContainers\MagicContainersApi;
use Valicm\BunnyToolkit\Api\Partner\PartnerApi;
use Valicm\BunnyToolkit\Api\Partner\Resource\Billing;
use Valicm\BunnyToolkit\Api\Partner\Resource\Customers;
use Valicm\BunnyToolkit\Api\Storage\Resource\ManageFiles;
use Valicm\BunnyToolkit\Api\Storage\StorageApi;
use Valicm\BunnyToolkit\Api\Stream\StreamApi;
use Valicm\BunnyToolkit\Bunny;
use Valicm\BunnyToolkit\StorageRegion;
use Valicm\BunnyToolkit\Tests\Support\FakeHttpClient;
use Valicm\BunnyToolkit\Tests\Support\TestApi;

/**
 * Spot checks that generated methods produce the requests Bunny documents.
 */
#[CoversClass(Bunny::class)]
#[CoversClass(CoreApi::class)]
#[CoversClass(PullZone::class)]
#[CoversClass(StorageApi::class)]
#[CoversClass(ManageFiles::class)]
#[CoversClass(StreamApi::class)]
#[CoversClass(MagicContainersApi::class)]
#[CoversClass(LoggingApi::class)]
#[CoversClass(Logs::class)]
#[CoversClass(LegacyLogs::class)]
#[CoversClass(PartnerApi::class)]
#[CoversClass(Customers::class)]
#[CoversClass(Billing::class)]
final class GeneratedApiTest extends TestCase
{
    private FakeHttpClient $http;

    private Bunny $bunny;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->bunny = TestApi::bunny($this->http);
    }

    public function testGetsAPullZone(): void
    {
        $this->http->respondJson(200, ['Id' => 42, 'Name' => 'my-zone']);

        $zone = $this->bunny->core()->pullZone()->getPullZone(42, includeCertificate: true);

        self::assertSame('my-zone', $zone['Name'] ?? null);
        self::assertSame('https://api.bunny.net/pullzone/42?includeCertificate=true', (string) $this->http->requests[0]->getUri());
        self::assertSame('GET', $this->http->requests[0]->getMethod());
    }

    public function testAddsAnEdgeRuleWithEnumValues(): void
    {
        $this->http->respond(201);

        $this->bunny->core()->pullZone()->addOrUpdateEdgeRule(42, [
            'ActionType' => EdgeRuleActionType::BlockRequest->value,
            'TriggerMatchingType' => TriggerMatchingTypes::MatchAll->value,
            'Triggers' => [
                ['Type' => TriggerTypes::Url->value, 'PatternMatches' => ['*/admin*'], 'PatternMatchingType' => PatternMatchingTypes::MatchAny->value],
            ],
            'Description' => 'Block admin',
            'Enabled' => true,
        ]);

        $request = $this->http->requests[0];
        self::assertSame('https://api.bunny.net/pullzone/42/edgerules/addOrUpdate', (string) $request->getUri());
        self::assertSame([
            'ActionType' => 4,
            'TriggerMatchingType' => 1,
            'Triggers' => [['Type' => 0, 'PatternMatches' => ['*/admin*'], 'PatternMatchingType' => 0]],
            'Description' => 'Block admin',
            'Enabled' => true,
        ], FakeHttpClient::jsonBody($request));
    }

    public function testPurgesAWholePullZoneWithoutABody(): void
    {
        $this->http->respond(204);

        $this->bunny->core()->pullZone()->purgeCache(42);

        self::assertSame('', (string) $this->http->requests[0]->getBody());
    }

    public function testUploadsAndDownloadsStorageFilesWithTheZonePassword(): void
    {
        $this->http->respond(201)->respond(200, 'file contents');
        $files = $this->bunny->storage('zone-password', StorageRegion::London)->manageFiles();

        $files->uploadFile('my-zone', 'images/2024', 'lot 1.jpg', 'jpeg bytes', checksum: 'ABC123');
        $download = $files->downloadFile('my-zone', 'images/2024', 'lot 1.jpg');

        $upload = $this->http->requests[0];
        self::assertSame('PUT', $upload->getMethod());
        self::assertSame('https://uk.storage.bunnycdn.com/my-zone/images/2024/lot%201.jpg', (string) $upload->getUri());
        self::assertSame('zone-password', $upload->getHeaderLine('AccessKey'));
        self::assertSame('ABC123', $upload->getHeaderLine('Checksum'));
        self::assertSame('jpeg bytes', (string) $upload->getBody());
        self::assertSame('file contents', (string) $download);
    }

    public function testStreamUsesTheLibraryKey(): void
    {
        $this->http->respondJson(200, ['guid' => 'abc']);

        $this->bunny->stream('library-key')->manageVideos()->getVideo(7, 'abc');

        $request = $this->http->requests[0];
        self::assertSame('https://video.bunnycdn.com/library/7/videos/abc', (string) $request->getUri());
        self::assertSame('library-key', $request->getHeaderLine('AccessKey'));
    }

    public function testMagicContainersUseTheirPathPrefix(): void
    {
        $this->http->respondJson(200, ['items' => []]);

        $this->bunny->magicContainers()->applications()->listApplications();

        self::assertStringStartsWith('https://api.bunny.net/mc/apps', (string) $this->http->requests[0]->getUri());
    }

    public function testAddingAPullZoneReturnsTheNewZone(): void
    {
        $this->http->respondJson(201, ['Id' => 77, 'Name' => 'new-zone']);

        $zone = $this->bunny->core()->pullZone()->addPullZone(['Name' => 'new-zone', 'OriginUrl' => 'https://origin.example']);

        self::assertSame(77, $zone['Id'] ?? null);
        self::assertSame('POST', $this->http->requests[0]->getMethod());
        self::assertSame('https://api.bunny.net/pullzone', (string) $this->http->requests[0]->getUri());
    }

    public function testPartnerCreatesACustomer(): void
    {
        $this->http->respondJson(200, ['CustomerUserId' => 'user-1']);

        $created = $this->bunny->partner()->customers()->createCustomer(['Email' => 'customer@example.com']);

        $request = $this->http->requests[0];
        self::assertSame('user-1', $created['CustomerUserId'] ?? null);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.bunny.net/partner/customers', (string) $request->getUri());
        self::assertSame('secret-key', $request->getHeaderLine('AccessKey'));
        self::assertSame(['Email' => 'customer@example.com'], json_decode((string) $request->getBody(), true));
    }

    public function testPartnerInvoicePdfIsAStream(): void
    {
        $this->http->respond(200, '%PDF-1.7', ['Content-Type' => 'application/pdf']);

        $pdf = $this->bunny->partner()->billing()->downloadInvoicePdf('user-1', 9);

        self::assertSame('%PDF-1.7', (string) $pdf);
        self::assertSame('https://api.bunny.net/partner/customer/user-1/billing/9/pdf', (string) $this->http->requests[0]->getUri());
    }

    public function testLoggingQueriesAPullZonesLogs(): void
    {
        $this->http->respondJson(200, ['data' => []]);

        $this->bunny->logging()->logs()->queryLogs(42, status: '404', limit: 50);

        $request = $this->http->requests[0];
        self::assertSame('https://logging.bunnycdn.com/v2/pullzones/42/logs?status=404&limit=50', (string) $request->getUri());
        self::assertSame('secret-key', $request->getHeaderLine('AccessKey'));
    }

    public function testLoggingDailyLogIsAStream(): void
    {
        $this->http->respond(200, "HIT|200|1700000000000|512\n");

        $log = $this->bunny->logging()->legacyLogs()->getDailyLog('10-27-26', 42);

        self::assertStringStartsWith('HIT|200', (string) $log);
        self::assertSame('https://logging.bunnycdn.com/10-27-26/42.log', (string) $this->http->requests[0]->getUri());
    }
}
