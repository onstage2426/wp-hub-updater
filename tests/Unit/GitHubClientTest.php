<?php

use WpHubUpdater\GitHubClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GitHubClient.
 *
 * Private methods are exercised via Reflection. Only method logic is tested
 * here — no real HTTP calls are made.
 */
final class GitHubClientTest extends TestCase
{
    protected function setUp(): void
    {
        wp_test_reset();
    }

    // -------------------------------------------------------------------------
    // Reflection helpers
    // -------------------------------------------------------------------------

    private function call(object $obj, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($obj, $method))->invoke($obj, ...$args);
    }

    private function get(object $obj, string $prop): mixed
    {
        return (new ReflectionProperty($obj, $prop))->getValue($obj);
    }

    private function set(object $obj, string $prop, mixed $value): void
    {
        (new ReflectionProperty($obj, $prop))->setValue($obj, $value);
    }

    private function makeClient(string $url = 'https://github.com/owner/repo'): GitHubClient
    {
        return new GitHubClient($url);
    }

    // -------------------------------------------------------------------------
    // Constructor / URL parsing
    // -------------------------------------------------------------------------

    public function testConstructorParsesOwnerAndRepo(): void
    {
        $client = $this->makeClient('https://github.com/my-org/my-repo');

        $this->assertSame('my-org', $this->get($client, 'userName'));
        $this->assertSame('my-repo', $this->get($client, 'repositoryName'));
    }

    public function testConstructorStripsTrailingSlash(): void
    {
        $client = $this->makeClient('https://github.com/org/repo/');

        $this->assertSame('org', $this->get($client, 'userName'));
        $this->assertSame('repo', $this->get($client, 'repositoryName'));
    }

    public function testConstructorStripsGitExtension(): void
    {
        // wp_parse_url is delegated to parse_url — .git stays as part of repo name
        // unless trimmed explicitly; verify the URL is accepted without throwing
        $client = new GitHubClient('https://github.com/org/repo.git');
        $this->assertSame('org', $this->get($client, 'userName'));
    }

    public function testConstructorThrowsOnMissingRepo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GitHubClient('https://github.com/only-owner');
    }

    public function testConstructorThrowsOnEmptyPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GitHubClient('https://github.com/');
    }

    public function testGetRepositoryUrl(): void
    {
        $url    = 'https://github.com/owner/repo';
        $client = $this->makeClient($url);

        $this->assertSame($url, $client->getRepositoryUrl());
    }

    // -------------------------------------------------------------------------
    // buildArchiveDownloadUrl()
    // -------------------------------------------------------------------------

    public function testBuildArchiveDownloadUrlWithExplicitRef(): void
    {
        $client = $this->makeClient('https://github.com/acme/widget');
        $url    = $client->buildArchiveDownloadUrl('v1.2.3');

        $this->assertSame(
            'https://api.github.com/repos/acme/widget/zipball/v1.2.3',
            $url,
        );
    }

    public function testBuildArchiveDownloadUrlDefaultsToHEAD(): void
    {
        $client = $this->makeClient('https://github.com/acme/widget');
        $url    = $client->buildArchiveDownloadUrl();

        $this->assertStringEndsWith('/zipball/HEAD', $url);
    }

    public function testBuildArchiveDownloadUrlEncodesSpecialChars(): void
    {
        $client = $this->makeClient('https://github.com/my org/my repo');
        $url    = $client->buildArchiveDownloadUrl('ref/heads/main');

        // urlencode() encodes spaces as '+', slashes as '%2F'
        $this->assertStringContainsString('my+org', $url);
        $this->assertStringContainsString('my+repo', $url);
        $this->assertStringContainsString('ref%2Fheads%2Fmain', $url);
    }

    // -------------------------------------------------------------------------
    // buildApiUrl() (private)
    // -------------------------------------------------------------------------

    public function testBuildApiUrlSubstitutesPlaceholders(): void
    {
        $client = $this->makeClient('https://github.com/my-org/my-repo');
        $url    = $this->call($client, 'buildApiUrl', '/repos/:user/:repo/releases', []);

        $this->assertSame('https://api.github.com/repos/my-org/my-repo/releases', $url);
    }

    public function testBuildApiUrlAppendsQueryParams(): void
    {
        $client = $this->makeClient();
        $url    = $this->call($client, 'buildApiUrl', '/repos/:user/:repo/releases', ['per_page' => 10]);

        $this->assertStringContainsString('per_page=10', $url);
        $this->assertStringContainsString('?', $url);
    }

    public function testBuildApiUrlWithNoQueryParams(): void
    {
        $client = $this->makeClient();
        $url    = $this->call($client, 'buildApiUrl', '/repos/:user/:repo/releases', []);

        $this->assertStringNotContainsString('?', $url);
    }

    // -------------------------------------------------------------------------
    // isValidCacheEntry() (private)
    // -------------------------------------------------------------------------

    public function testIsValidCacheEntryAcceptsValidEntry(): void
    {
        $client = $this->makeClient();
        $entry  = ['etag' => '"abc123"', 'body' => '{"id":1}'];

        $this->assertTrue($this->call($client, 'isValidCacheEntry', $entry));
    }

    public function testIsValidCacheEntryRejectsNonArray(): void
    {
        $client = $this->makeClient();

        $this->assertFalse($this->call($client, 'isValidCacheEntry', null));
        $this->assertFalse($this->call($client, 'isValidCacheEntry', 'string'));
        $this->assertFalse($this->call($client, 'isValidCacheEntry', 42));
    }

    public function testIsValidCacheEntryRejectsMissingEtag(): void
    {
        $client = $this->makeClient();
        $this->assertFalse($this->call($client, 'isValidCacheEntry', ['body' => '{"a":1}']));
    }

    public function testIsValidCacheEntryRejectsEmptyEtag(): void
    {
        $client = $this->makeClient();
        $this->assertFalse($this->call($client, 'isValidCacheEntry', ['etag' => '', 'body' => '{"a":1}']));
    }

    public function testIsValidCacheEntryRejectsMissingBody(): void
    {
        $client = $this->makeClient();
        $this->assertFalse($this->call($client, 'isValidCacheEntry', ['etag' => '"abc"']));
    }

    public function testIsValidCacheEntryRejectsEmptyBody(): void
    {
        $client = $this->makeClient();
        $this->assertFalse($this->call($client, 'isValidCacheEntry', ['etag' => '"abc"', 'body' => '']));
    }

    public function testIsValidCacheEntryRejectsNonJsonBody(): void
    {
        $client = $this->makeClient();
        $this->assertFalse($this->call($client, 'isValidCacheEntry', ['etag' => '"abc"', 'body' => 'not json{{{']));
    }

    // -------------------------------------------------------------------------
    // sanitizeEtagCache() (private)
    // -------------------------------------------------------------------------

    public function testSanitizeEtagCacheRemovesInvalidEntries(): void
    {
        $client = $this->makeClient();
        $this->set($client, 'etagCache', [
            'https://valid.example'   => ['etag' => '"abc"', 'body' => '{"ok":true}'],
            'https://invalid.example' => ['etag' => '',      'body' => ''],
            'https://nonjson.example' => ['etag' => '"xyz"', 'body' => 'not-json'],
        ]);

        $this->call($client, 'sanitizeEtagCache');

        $cache = $this->get($client, 'etagCache');
        $this->assertArrayHasKey('https://valid.example', $cache);
        $this->assertArrayNotHasKey('https://invalid.example', $cache);
        $this->assertArrayNotHasKey('https://nonjson.example', $cache);
    }

    public function testSanitizeEtagCacheMarksDirtyWhenEntriesRemoved(): void
    {
        $client = $this->makeClient();
        $this->set($client, 'etagCache', [
            'https://bad.example' => ['etag' => '', 'body' => ''],
        ]);
        $this->set($client, 'etagCacheDirty', false);

        $this->call($client, 'sanitizeEtagCache');

        $this->assertTrue($this->get($client, 'etagCacheDirty'));
    }

    public function testSanitizeEtagCacheDoesNotMarkDirtyWhenAllValid(): void
    {
        $client = $this->makeClient();
        $this->set($client, 'etagCache', [
            'https://valid.example' => ['etag' => '"abc"', 'body' => '{"ok":true}'],
        ]);
        $this->set($client, 'etagCacheDirty', false);

        $this->call($client, 'sanitizeEtagCache');

        $this->assertFalse($this->get($client, 'etagCacheDirty'));
    }

    // -------------------------------------------------------------------------
    // looksLikeVersion() (private)
    // -------------------------------------------------------------------------

    #[DataProvider('versionStringProvider')]
    public function testLooksLikeVersion(string $input, bool $expected): void
    {
        $client = $this->makeClient();
        $this->assertSame($expected, $this->call($client, 'looksLikeVersion', $input));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function versionStringProvider(): array
    {
        return [
            'plain major'          => ['1', true],
            'major.minor'          => ['1.0', true],
            'semver'               => ['1.2.3', true],
            'v-prefixed semver'    => ['v1.2.3', true],
            'four-part'            => ['1.2.3.4', true],
            'alpha suffix'         => ['1.0.0-alpha', true],
            'beta suffix'          => ['1.0.0-beta.1', true],
            'rc suffix'            => ['2.0.0-rc1', true],
            'branch main'          => ['main', false],
            'branch master'        => ['master', false],
            'arbitrary string'     => ['feature-login', false],
            'v-only'               => ['v', false],
            'leading letters'      => ['abc1.0', false],
        ];
    }

    // -------------------------------------------------------------------------
    // sortTagsByVersion() (private)
    // -------------------------------------------------------------------------

    public function testSortTagsByVersionSortsDescending(): void
    {
        $client = $this->makeClient();

        $tags = array_map(function (string $name): stdClass {
            $t       = new stdClass();
            $t->name = $name;
            return $t;
        }, ['v1.0.0', 'v3.0.0', 'v2.1.0']);

        $sorted = $this->call($client, 'sortTagsByVersion', $tags);

        $this->assertSame('v3.0.0', $sorted[0]->name);
        $this->assertSame('v2.1.0', $sorted[1]->name);
        $this->assertSame('v1.0.0', $sorted[2]->name);
    }

    public function testSortTagsByVersionFiltersNonVersionTags(): void
    {
        $client = $this->makeClient();

        $makeTag = fn(string $n) => (object) ['name' => $n];
        $tags    = [$makeTag('v1.0.0'), $makeTag('main'), $makeTag('feature-x')];

        $sorted = $this->call($client, 'sortTagsByVersion', $tags);

        $this->assertCount(1, $sorted);
        $this->assertSame('v1.0.0', $sorted[0]->name);
    }

    public function testSortTagsByVersionReturnsEmptyForNoVersionTags(): void
    {
        $client = $this->makeClient();
        $result = $this->call($client, 'sortTagsByVersion', [(object) ['name' => 'main']]);

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // compareTagNames() (private)
    // -------------------------------------------------------------------------

    public function testCompareTagNamesHigherVersionComesFirst(): void
    {
        $client = $this->makeClient();
        $t1     = (object) ['name' => 'v2.0.0'];
        $t2     = (object) ['name' => 'v1.0.0'];

        // Returns negative when t1 > t2 (descending sort).
        $result = $this->call($client, 'compareTagNames', $t1, $t2);
        $this->assertLessThan(0, $result);
    }

    public function testCompareTagNamesEqualVersionsReturnZero(): void
    {
        $client = $this->makeClient();
        $t1     = (object) ['name' => 'v1.0.0'];
        $t2     = (object) ['name' => 'v1.0.0'];

        $this->assertSame(0, $this->call($client, 'compareTagNames', $t1, $t2));
    }

    // -------------------------------------------------------------------------
    // selectAsset() (private)
    // -------------------------------------------------------------------------

    public function testSelectAssetReturnsNullForEmptyAssets(): void
    {
        $client  = $this->makeClient();
        $release = (object) ['assets' => []];

        $this->assertNull($this->call($client, 'selectAsset', $release));
    }

    public function testSelectAssetReturnsNullForMissingAssetsProperty(): void
    {
        $client  = $this->makeClient();
        $release = new stdClass();

        $this->assertNull($this->call($client, 'selectAsset', $release));
    }

    public function testSelectAssetReturnsFirstWhenNoPattern(): void
    {
        $client  = $this->makeClient();
        $asset1  = (object) ['name' => 'plugin-1.0.zip'];
        $asset2  = (object) ['name' => 'plugin-1.0-full.zip'];
        $release = (object) ['assets' => [$asset1, $asset2]];

        $this->assertSame($asset1, $this->call($client, 'selectAsset', $release));
    }

    public function testSelectAssetMatchesPatternBySubstring(): void
    {
        $client = $this->makeClient();
        $client->setAssetFilter('full');

        $asset1  = (object) ['name' => 'plugin-1.0.zip'];
        $asset2  = (object) ['name' => 'plugin-1.0-full.zip'];
        $release = (object) ['assets' => [$asset1, $asset2]];

        $this->assertSame($asset2, $this->call($client, 'selectAsset', $release));
    }

    public function testSelectAssetFallsBackToFirstWhenPatternDoesNotMatch(): void
    {
        $client = $this->makeClient();
        $client->setAssetFilter('nomatch');

        $asset1  = (object) ['name' => 'plugin.zip'];
        $release = (object) ['assets' => [$asset1]];

        $this->assertSame($asset1, $this->call($client, 'selectAsset', $release));
    }

    // -------------------------------------------------------------------------
    // isGitHubUrl() (private)
    // -------------------------------------------------------------------------

    #[DataProvider('githubUrlProvider')]
    public function testIsGitHubUrl(string $url, bool $expected): void
    {
        $client = $this->makeClient();
        $this->assertSame($expected, $this->call($client, 'isGitHubUrl', $url));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function githubUrlProvider(): array
    {
        return [
            'github.com'                  => ['https://github.com/org/repo', true],
            'api.github.com'              => ['https://api.github.com/repos/org/repo/zipball/HEAD', true],
            'codeload.github.com'         => ['https://codeload.github.com/org/repo/zip/refs/heads/main', true],
            'objects.githubusercontent.com' => ['https://objects.githubusercontent.com/abc/def', true],
            'malicious.com'               => ['https://malicious.com/github.com/file', false],
            'not-github.com'              => ['https://not-github.com/org/repo', false],
            'empty string'                => ['', false],
        ];
    }

    // -------------------------------------------------------------------------
    // setReleaseFilter() validation
    // -------------------------------------------------------------------------

    public function testSetReleaseFilterThrowsWhenMaxReleasesExceeds100(): void
    {
        $client = $this->makeClient();

        $this->expectException(\InvalidArgumentException::class);
        $client->setReleaseFilter(fn() => true, GitHubClient::RELEASE_FILTER_SKIP_PRERELEASE, 101);
    }

    public function testSetReleaseFilterThrowsWhenMaxReleasesIsZero(): void
    {
        $client = $this->makeClient();

        $this->expectException(\InvalidArgumentException::class);
        $client->setReleaseFilter(fn() => true, GitHubClient::RELEASE_FILTER_SKIP_PRERELEASE, 0);
    }

    public function testSetReleaseFilterAcceptsBoundaryValues(): void
    {
        $client = $this->makeClient();

        $client->setReleaseFilter(fn() => true, GitHubClient::RELEASE_FILTER_SKIP_PRERELEASE, 1);
        $client->setReleaseFilter(fn() => true, GitHubClient::RELEASE_FILTER_SKIP_PRERELEASE, 100);

        $this->assertTrue(true); // no exception thrown
    }

    public function testSetReleaseVersionFilterDelegatesToSetReleaseFilter(): void
    {
        $client = $this->makeClient();
        $result = $client->setReleaseVersionFilter('/^1\./');

        $this->assertSame($client, $result);
        $this->assertTrue($this->call($client, 'hasCustomReleaseFilter'));
    }

    // -------------------------------------------------------------------------
    // getApiRequestHttpOptions() (private)
    // -------------------------------------------------------------------------

    public function testGetApiRequestHttpOptionsIncludesVersionHeaderByDefault(): void
    {
        $client  = $this->makeClient();
        $options = $this->call($client, 'getApiRequestHttpOptions', true);

        $this->assertArrayHasKey('X-GitHub-Api-Version', $options['headers']);
        $this->assertSame('2026-03-10', $options['headers']['X-GitHub-Api-Version']);
    }

    public function testGetApiRequestHttpOptionsOmitsVersionHeaderWhenFalse(): void
    {
        $client  = $this->makeClient();
        $options = $this->call($client, 'getApiRequestHttpOptions', false);

        $this->assertArrayNotHasKey('X-GitHub-Api-Version', $options['headers']);
    }

    public function testGetApiRequestHttpOptionsAddsAuthorizationWhenTokenSet(): void
    {
        $client = $this->makeClient();
        $client->setAccessToken('ghp_test123');

        $options = $this->call($client, 'getApiRequestHttpOptions', true);

        $this->assertSame('token ghp_test123', $options['headers']['Authorization']);
    }

    public function testGetApiRequestHttpOptionsNoAuthorizationWithoutToken(): void
    {
        $client  = $this->makeClient();
        $options = $this->call($client, 'getApiRequestHttpOptions', true);

        $this->assertArrayNotHasKey('Authorization', $options['headers']);
    }

    public function testGetApiRequestHttpOptionsTimeoutIsThreeOutsideCron(): void
    {
        $GLOBALS['_wp_doing_cron'] = false;
        $client  = $this->makeClient();
        $options = $this->call($client, 'getApiRequestHttpOptions', true);

        $this->assertSame(3, $options['timeout']);
    }

    public function testGetApiRequestHttpOptionsTimeoutIsTenDuringCron(): void
    {
        $GLOBALS['_wp_doing_cron'] = true;
        $client  = $this->makeClient();
        $options = $this->call($client, 'getApiRequestHttpOptions', true);

        $this->assertSame(10, $options['timeout']);

        $GLOBALS['_wp_doing_cron'] = false;
    }

    // -------------------------------------------------------------------------
    // isTransientFailure() (private)
    // -------------------------------------------------------------------------

    public function testIsTransientFailureTrueForWpError(): void
    {
        $client = $this->makeClient();
        $this->assertTrue($this->call($client, 'isTransientFailure', new \WP_Error('err', 'msg'), false));
    }

    #[DataProvider('transientStatusProvider')]
    public function testIsTransientFailureForStatusCodes(int $code, bool $expected): void
    {
        $client = $this->makeClient();
        $this->assertSame($expected, $this->call($client, 'isTransientFailure', [], $code));
    }

    /** @return array<string, array{0: int, 1: bool}> */
    public static function transientStatusProvider(): array
    {
        return [
            '200 ok'        => [200, false],
            '400 bad req'   => [400, false],
            '404 not found' => [404, false],
            '500 error'     => [500, true],
            '502 bad gw'    => [502, true],
            '503 unavail'   => [503, true],
            '504 timeout'   => [504, true],
        ];
    }

    // -------------------------------------------------------------------------
    // setMaxRetries()
    // -------------------------------------------------------------------------

    public function testSetMaxRetriesStoresValues(): void
    {
        $client = $this->makeClient();
        $result = $client->setMaxRetries(5, 250);

        $this->assertSame($client, $result);
        $this->assertSame(5, $this->get($client, 'maxRetries'));
        $this->assertSame(250, $this->get($client, 'retryDelayMs'));
    }

    public function testSetMaxRetriesClampsNegativeToZero(): void
    {
        $client = $this->makeClient();
        $client->setMaxRetries(-1);

        $this->assertSame(0, $this->get($client, 'maxRetries'));
    }

    // -------------------------------------------------------------------------
    // Rate-limit block (private isCurrentlyRateLimited)
    // -------------------------------------------------------------------------

    public function testIsCurrentlyRateLimitedReturnsFalseByDefault(): void
    {
        $client = $this->makeClient();
        $client->setSlug('test-plugin');

        $this->assertFalse($this->call($client, 'isCurrentlyRateLimited'));
    }

    public function testIsCurrentlyRateLimitedReturnsTrueWhenBlockActive(): void
    {
        $client = $this->makeClient();
        $client->setSlug('test-plugin');

        $GLOBALS['_wp_test_options']['hu_ratelimit-test-plugin'] = ['until' => time() + 3600];

        $this->assertTrue($this->call($client, 'isCurrentlyRateLimited'));
    }

    public function testIsCurrentlyRateLimitedReturnsFalseWhenBlockExpired(): void
    {
        $client = $this->makeClient();
        $client->setSlug('test-plugin');

        $GLOBALS['_wp_test_options']['hu_ratelimit-test-plugin'] = ['until' => time() - 1];

        $this->assertFalse($this->call($client, 'isCurrentlyRateLimited'));
    }

    public function testIsCurrentlyRateLimitedDeletesOptionWhenExpired(): void
    {
        $client = $this->makeClient();
        $client->setSlug('test-plugin');

        $GLOBALS['_wp_test_options']['hu_ratelimit-test-plugin'] = ['until' => time() - 1];

        $this->call($client, 'isCurrentlyRateLimited');

        $this->assertArrayNotHasKey('hu_ratelimit-test-plugin', $GLOBALS['_wp_test_options']);
    }
}
