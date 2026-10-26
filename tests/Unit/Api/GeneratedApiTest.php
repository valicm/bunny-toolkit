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
use Valicm\BunnyToolkit\Api\MagicContainers\MagicContainersApi;
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
}
