<?php

declare(strict_types=1);

namespace Fahoba\NextcloudPhotos;

use DOMDocument;
use DOMXPath;
use Fahoba\PluginApi\Connections\ConnectedAccount;
use Fahoba\PluginApi\Connections\CredentialField;
use Fahoba\PluginApi\Connections\DescribesSetup;
use Fahoba\PluginApi\Connections\UsesCredentials;
use Fahoba\PluginApi\Http\Outbound;
use Fahoba\PluginApi\Photos\Photo;
use Fahoba\PluginApi\Photos\RemotePhotoSource;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A folder of the family's Nextcloud, subfolders included, read over WebDAV
 * with an app password — the same folder the phones' Nextcloud app uploads
 * the camera roll into, if it is set up so.
 *
 * Plain WebDAV instead of the Photos app's albums: that interface changed
 * between Nextcloud versions, a folder works on every one. The day taken
 * comes from Nextcloud's photo metadata (28 and later); without it the
 * picture counts as undated, never as taken on its upload day.
 */
final class NextcloudPhotoSource extends RemotePhotoSource implements DescribesSetup, UsesCredentials
{
    public const KEY = 'fahoba/nextcloud-photos';

    /** Folders below the chosen one are read this deep, and at most this many pictures. */
    private const DEPTH = 4;

    private const MAX_FILES = 5000;

    private const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public function __construct(private readonly Outbound $outbound) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Nextcloud';
    }

    public function isConfigured(?ConnectedAccount $account): bool
    {
        $credentials = $account?->credentials() ?? [];

        return parent::isConfigured($account)
            && self::base($credentials['url'] ?? null) !== null
            && trim((string) ($credentials['user'] ?? '')) !== ''
            && trim((string) ($credentials['password'] ?? '')) !== '';
    }

    protected function listing(ConnectedAccount $account): array
    {
        $photos = [];
        $this->walk($account->credentials(), self::folder($account->credentials()), 0, $photos);

        return $photos;
    }

    protected function download(ConnectedAccount $account, string $id): ?string
    {
        $relative = self::decodeId($id);

        if ($relative === null) {
            return null;
        }

        $response = $this->request($account->credentials(), 'GET', self::folder($account->credentials()).$relative, [], 30);

        return $response?->successful() ? $response->body() : null;
    }

    public function icon(): string
    {
        return 'heroicon-o-cloud';
    }

    public function description(): string
    {
        return __('Shows a folder of your Nextcloud in the photo frame, subfolders included.');
    }

    public function setupNotes(): array
    {
        return [
            __('Create an app password in Nextcloud under Settings → Security; your own password is not needed here.'),
            __('The server must be reachable from the internet at this address — an address only valid at home is refused.'),
            __('New pictures in the folder appear within ten minutes.'),
        ];
    }

    public function credentialFields(): array
    {
        return [
            new CredentialField(name: 'url', label: __('Address'), placeholder: 'https://cloud.example.org'),
            new CredentialField(name: 'user', label: __('User name')),
            new CredentialField(name: 'password', label: __('App password'), secret: true),
            new CredentialField(name: 'folder', label: __('Folder'), required: false, placeholder: 'Photos/Familie', hint: __('Empty: the whole Photos folder.')),
        ];
    }

    public function verifyCredentials(array $credentials): ?string
    {
        if (self::base($credentials['url'] ?? null) === null) {
            return __('The address starts with https:// or http://.');
        }

        $response = $this->propfind($credentials, self::folder($credentials));

        if ($response === null) {
            return __('The server cannot be reached at this address, or the address is private.');
        }

        if ($response->status() === 401) {
            return __('Nextcloud does not accept this user name and app password.');
        }

        if ($response->status() === 404) {
            return __('This folder does not exist in this Nextcloud.');
        }

        return $response->status() === 207 ? null : __('There is no Nextcloud at this address.');
    }

    public function accountLabel(array $credentials): string
    {
        return 'Nextcloud – '.trim(self::folderName($credentials), '/');
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<int, Photo>  $photos
     */
    private function walk(array $credentials, string $folder, int $depth, array &$photos): void
    {
        $response = $this->propfind($credentials, $folder);

        if ($response === null || $response->status() !== 207) {
            throw new RuntimeException('Nextcloud did not list the folder.');
        }

        $root = self::folder($credentials);

        foreach (self::entries($response->body()) as $entry) {
            if (count($photos) >= self::MAX_FILES) {
                return;
            }

            $path = rawurldecode((string) parse_url($entry['href'], PHP_URL_PATH));
            $start = strpos($path, $root);

            if ($start === false) {
                continue;
            }

            $relative = substr($path, $start + strlen($root));

            if ($relative === '' || rtrim($folder, '/') === rtrim($path, '/')) {
                continue;
            }

            if ($entry['collection']) {
                if ($depth < self::DEPTH) {
                    $this->walk($credentials, $root.$relative, $depth + 1, $photos);
                }

                continue;
            }

            if (in_array($entry['type'], self::TYPES, true)) {
                $photos[] = new Photo(id: self::encodeId($relative), takenOn: $entry['taken']);
            }
        }
    }

    /**
     * @return list<array{href: string, collection: bool, type: string, taken: string|null}>
     */
    private static function entries(string $xml): array
    {
        $document = new DOMDocument;

        if ($xml === '' || ! $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', 'DAV:');
        $xpath->registerNamespace('nc', 'http://nextcloud.org/ns');
        $entries = [];

        foreach ($xpath->query('//d:response') ?: [] as $response) {
            $taken = trim((string) $xpath->evaluate('string(.//nc:metadata-photos-original_date_time)', $response));

            $entries[] = [
                'href' => trim((string) $xpath->evaluate('string(d:href)', $response)),
                'collection' => $xpath->query('.//d:resourcetype/d:collection', $response)?->length > 0,
                'type' => strtolower(trim((string) $xpath->evaluate('string(.//d:getcontenttype)', $response))),
                'taken' => ctype_digit($taken) ? gmdate('Y-m-d', (int) $taken) : null,
            ];
        }

        return $entries;
    }

    /** @param  array<string, mixed>  $credentials */
    private function propfind(array $credentials, string $path): ?Response
    {
        return $this->request($credentials, 'PROPFIND', $path, [
            'headers' => ['Depth' => '1', 'Content-Type' => 'application/xml'],
            'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns"><d:prop>'
                .'<d:resourcetype/><d:getcontenttype/><nc:metadata-photos-original_date_time/></d:prop></d:propfind>',
        ]);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $options
     */
    private function request(array $credentials, string $method, string $path, array $options = [], int $timeout = 15): ?Response
    {
        $base = self::base($credentials['url'] ?? null);

        if ($base === null) {
            return null;
        }

        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));

        return $this->outbound->householdSend($method, $base.$encoded, [
            ...$options,
            'auth' => [trim((string) ($credentials['user'] ?? '')), (string) ($credentials['password'] ?? '')],
        ], $timeout);
    }

    /** The WebDAV path of the chosen folder, with slashes on both ends. */
    private static function folder(array $credentials): string
    {
        return '/remote.php/dav/files/'.trim((string) ($credentials['user'] ?? '')).'/'.self::folderName($credentials);
    }

    /** The folder below the user's root; `..` never leads out of it. */
    private static function folderName(array $credentials): string
    {
        $parts = array_filter(
            explode('/', str_replace('\\', '/', trim((string) ($credentials['folder'] ?? '')))),
            static fn (string $part): bool => $part !== '' && $part !== '.' && $part !== '..',
        );

        return ($parts === [] ? 'Photos' : implode('/', $parts)).'/';
    }

    private static function base(mixed $url): ?string
    {
        $url = rtrim(trim((string) $url), '/');

        return preg_match('#^https?://[^/\s]+#i', $url) === 1 ? (string) preg_replace('#/remote\.php.*$#', '', $url) : null;
    }
}
