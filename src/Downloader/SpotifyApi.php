<?php

declare(strict_types=1);

namespace Doniixify\Downloader;

use Doniixify\Env;

final class SpotifyApi
{
    private static ?string $token = null;
    private static int $tokenExpiresAt = 0;

    public static function search(string $query, int $limit = 50, string $type = 'title'): array
    {
        $type = $type === 'artist' ? 'artist' : 'title';

        $spotifyResults = self::searchSpotify($query, $limit, $type);
        if (!empty($spotifyResults)) {
            $hasRealPopularity = false;
            foreach ($spotifyResults as $r) {
                if (!empty($r['popularity']) && (int)$r['popularity'] > 0) { $hasRealPopularity = true; break; }
            }
            if ($hasRealPopularity) {
                usort($spotifyResults, function ($a, $b) {
                    $pa = (int)($a['popularity'] ?? 0);
                    $pb = (int)($b['popularity'] ?? 0);
                    return $pb <=> $pa;
                });
                self::log("Spotify Web API: " . count($spotifyResults) . " items for \"{$query}\" [sorted by popularity, top=" . ($spotifyResults[0]['popularity'] ?? '?') . "]");
            } else {
                self::log("Spotify Web API: " . count($spotifyResults) . " items for \"{$query}\" [popularity null/0, using native Spotify order]");
            }
            return array_slice($spotifyResults, 0, $limit);
        }

        if ($type === 'artist') {
            $itunes = self::searchItunes($query, 200, $type);
            $deezerSearch = self::searchDeezer($query, 9999, $type);
            $deezerArtist = self::searchDeezerArtistDiscography($query, 9999);
            $combined = array_merge($deezerArtist, $deezerSearch, $itunes);
            self::log("artist mode (no Spotify): " . count($deezerArtist) . " discography + " . count($deezerSearch) . " search + " . count($itunes) . " iTunes candidates");
            $deduped = self::mergeDedupAlbumAware($combined, 9999);
            return self::rankByRelevance($deduped, $query, $type, $limit);
        }

        $itunes = self::searchItunes($query, 100, $type);
        $deezer = self::searchDeezer($query, 100, $type);
        $combined = array_merge($deezer, $itunes);
        $merged = self::mergeDedup($combined, [], 200);
        self::log("title mode (no Spotify): " . count($deezer) . " Deezer + " . count($itunes) . " iTunes = " . count($merged) . " deduped");
        return self::rankByRelevance($merged, $query, $type, $limit);
    }

    private static function searchSpotify(string $query, int $limit, string $type): array
    {
        $token = self::getAccessToken();
        if ($token === '') return [];

        $market = strtoupper(trim((string)Env::get('SPOTIFY_MARKET', 'PL')));
        if (!preg_match('/^[A-Z]{2}$/', $market)) $market = 'PL';

        $merged = [];
        $seen = [];

        // STEP 1: jeśli query jest krótki (1-3 słowa), spróbuj jako artist name -> top tracks
        $wordCount = count(preg_split('/\s+/', trim($query)) ?: []);
        if ($wordCount >= 1 && $wordCount <= 4) {
            $artistTracks = self::artistTopTracks($query, $token, $market);
            foreach ($artistTracks as $track) {
                $sid = (string)($track['id'] ?? '');
                if ($sid === '') continue;
                $norm = self::normalizeSpotifyTrack($track);
                $norm['popularity'] = min(100, ($norm['popularity'] ?? 0) + 50);
                $merged[] = $norm;
                $seen[$sid] = count($merged) - 1;
            }
            if (!empty($artistTracks)) {
                self::log("Spotify artist top-tracks: " . count($artistTracks) . " hits for artist match of \"{$query}\"");
            }
        }

        // STEP 2: multi-variant search tracków
        $queries = self::buildSpotifyQueries($query, $type);
        foreach ($queries as $variant) {
            [$qStr, $boost] = $variant;
            $url = 'https://api.spotify.com/v1/search?'
                . 'q=' . urlencode($qStr)
                . '&type=track'
                . '&limit=' . max(1, min(50, $limit))
                . '&market=' . $market;
            [$body, $status] = self::request($url, $token);
            if ($status !== 200 || $body === '') {
                self::log("Spotify search HTTP {$status} for q=\"{$qStr}\"");
                continue;
            }
            $data = json_decode($body, true);
            if (!is_array($data)) continue;
            $countThisVariant = 0;
            foreach ((array)($data['tracks']['items'] ?? []) as $track) {
                if (!is_array($track) || empty($track['name']) || empty($track['id'])) continue;
                $sid = (string)$track['id'];
                if (isset($seen[$sid])) {
                    if ($boost > 0) {
                        $idx = $seen[$sid];
                        $merged[$idx]['popularity'] = min(100, ($merged[$idx]['popularity'] ?? 0) + $boost);
                    }
                    continue;
                }
                $norm = self::normalizeSpotifyTrack($track);
                if ($boost > 0) $norm['popularity'] = min(100, ($norm['popularity'] ?? 0) + $boost);
                $merged[] = $norm;
                $seen[$sid] = count($merged) - 1;
                $countThisVariant++;
            }
            self::log("Spotify variant q=\"{$qStr}\" boost=+{$boost} -> {$countThisVariant} new items");
        }
        return $merged;
    }

    private static function artistTopTracks(string $query, string $token, string $market): array
    {
        $url = 'https://api.spotify.com/v1/search?'
            . 'q=' . urlencode($query)
            . '&type=artist'
            . '&limit=3'
            . '&market=' . $market;
        [$body, $status] = self::request($url, $token);
        if ($status !== 200 || $body === '') return [];
        $data = json_decode($body, true);
        $artists = (array)($data['artists']['items'] ?? []);
        if (empty($artists)) return [];

        $qLower = mb_strtolower(trim($query));
        $matchedArtistId = null;
        foreach ($artists as $a) {
            if (!is_array($a) || empty($a['id']) || empty($a['name'])) continue;
            $aName = mb_strtolower((string)$a['name']);
            if ($aName === $qLower || str_contains($aName, $qLower) || str_contains($qLower, $aName)) {
                $matchedArtistId = (string)$a['id'];
                self::log("matched artist '{$a['name']}' (id={$matchedArtistId}, popularity=" . ($a['popularity'] ?? '?') . ", followers=" . ($a['followers']['total'] ?? '?') . ")");
                break;
            }
        }
        if ($matchedArtistId === null) {
            $first = $artists[0];
            $matchedArtistId = (string)($first['id'] ?? '');
            if ($matchedArtistId === '') return [];
            self::log("no exact artist match for \"{$query}\", using first result: " . ($first['name'] ?? '?'));
        }

        $topUrl = 'https://api.spotify.com/v1/artists/' . urlencode($matchedArtistId) . '/top-tracks?market=' . $market;
        [$topBody, $topStatus] = self::request($topUrl, $token);
        if ($topStatus !== 200 || $topBody === '') return [];
        $topData = json_decode($topBody, true);
        return (array)($topData['tracks'] ?? []);
    }

    private static function buildSpotifyQueries(string $query, string $type): array
    {
        $q = trim($query);
        if ($q === '') return [];

        $variants = [];
        if ($type === 'artist') {
            $variants[] = ['artist:"' . str_replace('"', '', $q) . '"', 25];
            $variants[] = [$q, 0];
        } else {
            $variants[] = [$q, 0];
            $variants[] = ['artist:"' . str_replace('"', '', $q) . '"', 30];
            if (mb_strlen($q) >= 3 && !str_contains($q, '"')) {
                $variants[] = ['track:"' . $q . '"', 10];
            }
        }
        return $variants;
    }

    private static function normalizeSpotifyTrack(array $track): array
    {
        $artists = [];
        foreach ((array)($track['artists'] ?? []) as $a) {
            if (!empty($a['name'])) $artists[] = ['name' => (string)$a['name'], 'id' => (string)($a['id'] ?? '')];
        }
        $cover = '';
        $images = $track['album']['images'] ?? [];
        if (is_array($images) && !empty($images[0]['url'])) $cover = (string)$images[0]['url'];

        $sid = (string)($track['id'] ?? '');
        return [
            'name' => (string)$track['name'],
            'song_id' => $sid,
            'id' => $sid,
            'url' => $sid !== '' ? 'https://open.spotify.com/track/' . $sid : '',
            'artists' => $artists,
            'duration_ms' => (int)($track['duration_ms'] ?? 0),
            'popularity' => (int)($track['popularity'] ?? 0),
            'explicit' => (bool)($track['explicit'] ?? false),
            'album' => [
                'id' => (string)($track['album']['id'] ?? ''),
                'name' => (string)($track['album']['name'] ?? ''),
                'release_date' => (string)($track['album']['release_date'] ?? ''),
                'images' => $images,
            ],
            'cover_url' => $cover,
        ];
    }

    private static function rankByRelevance(array $items, string $query, string $type, int $limit): array
    {
        $q = mb_strtolower(trim($query));
        if ($q === '') return array_slice($items, 0, $limit);

        $qWords = array_values(array_filter(preg_split('#\s+#u', $q) ?: [], fn($w) => mb_strlen($w) >= 2));

        $strictArtistPatterns = [];
        if ($type === 'artist') {
            $primaryName = trim((preg_split('#\s*(?:&|,|×|\bft\.?\b|\bfeat\.?\b|\bvs\.?\b|\bx\b)\s*#iu', $q) ?: [$q])[0]);
            $candidates = array_unique(array_filter([$q, $primaryName], fn($s) => mb_strlen($s) >= 2));
            foreach ($candidates as $c) {
                $quoted = preg_quote($c, '/');
                $strictArtistPatterns[] = '/^' . $quoted . '(?:$|[\s&,;\/×()\[\]\-])/iu';
                $strictArtistPatterns[] = '/(?:&|,|×|\bfeat\.?\b|\bft\.?\b|\bvs\.?\b|\bx\b)\s*' . $quoted . '(?:$|[\s&,;\/×()\[\]\-])/iu';
            }
        }

        $scored = [];
        foreach ($items as $idx => $item) {
            if (!is_array($item)) continue;
            $title = mb_strtolower((string)($item['name'] ?? ''));
            $artist = '';
            if (!empty($item['artists'][0]['name'])) {
                $artist = mb_strtolower((string)$item['artists'][0]['name']);
            }
            $album = mb_strtolower((string)($item['album']['name'] ?? ''));

            $primary = $type === 'artist' ? $artist : $title;
            $secondary = $type === 'artist' ? $title : $artist;

            if (!empty($strictArtistPatterns)) {
                if ($primary === '') continue;
                $matched = false;
                foreach ($strictArtistPatterns as $pat) {
                    if (preg_match($pat, $primary)) { $matched = true; break; }
                }
                if (!$matched) continue;
            }

            $score = 0;
            if ($primary !== '') {
                if ($primary === $q) $score += 1000;
                elseif (str_starts_with($primary, $q)) $score += 600;
                elseif (str_contains($primary, $q)) $score += 300;
                else {
                    $hits = 0;
                    foreach ($qWords as $w) {
                        if (str_contains($primary, $w)) $hits++;
                    }
                    if ($hits > 0) $score += $hits * 60;
                }
            }
            if ($secondary !== '') {
                if (str_contains($secondary, $q)) $score += 100;
                else {
                    foreach ($qWords as $w) {
                        if (str_contains($secondary, $w)) $score += 20;
                    }
                }
            }
            if ($album !== '' && str_contains($album, $q)) $score += 30;

            if ($score < 60) continue;

            $popularity = (int)($item['popularity'] ?? 0);
            $popularityBoost = (int)round($popularity * 5);
            $score += $popularityBoost;

            $scored[] = ['score' => $score, 'pop' => $popularity, 'idx' => $idx, 'item' => $item];
        }

        usort($scored, function ($a, $b) {
            if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
            if ($a['pop'] !== $b['pop']) return $b['pop'] <=> $a['pop'];
            return $a['idx'] <=> $b['idx'];
        });

        self::log("ranked " . count($scored) . " items (of " . count($items) . " candidates) for \"{$query}\" [type={$type}]");
        return array_slice(array_column($scored, 'item'), 0, $limit);
    }

    private static function mergeDedupAlbumAware(array $items, int $limit): array
    {
        $seen = [];
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $artist = '';
            if (!empty($item['artists'][0]['name'])) $artist = (string)$item['artists'][0]['name'];
            $title = (string)($item['name'] ?? '');
            $album = (string)($item['album']['name'] ?? '');
            if ($title === '') continue;
            $key = self::dedupKey($artist, $title) . '||' . mb_strtolower(trim($album));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $result[] = $item;
            if (count($result) >= $limit) break;
        }
        return $result;
    }

    private static function mergeDedup(array $primary, array $secondary, int $limit): array
    {
        $seen = [];
        $result = [];
        foreach (array_merge($primary, $secondary) as $item) {
            if (!is_array($item)) continue;
            $artist = '';
            if (!empty($item['artists'][0]['name'])) $artist = (string)$item['artists'][0]['name'];
            $title = (string)($item['name'] ?? '');
            $key = self::dedupKey($artist, $title);
            if ($key === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $result[] = $item;
            if (count($result) >= $limit) break;
        }
        return $result;
    }

    private static function dedupKey(string $artist, string $title): string
    {
        $norm = function (string $s): string {
            $s = mb_strtolower($s);
            $s = preg_replace('#\([^)]*\)|\[[^\]]*\]#u', '', $s) ?? $s;
            $s = preg_replace('#\b(official|video|music|lyric[s]?|audio|hd|hq|remaster(ed)?|version|feat\.?|ft\.?)\b#iu', '', $s) ?? $s;
            $s = preg_replace('#[^\p{L}\p{N}\s]#u', ' ', $s) ?? $s;
            $s = preg_replace('#\s+#u', ' ', $s) ?? $s;
            return trim($s);
        };
        $a = $norm($artist);
        $t = $norm($title);
        if ($t === '') return '';
        return $a . '||' . $t;
    }

    private static function searchItunes(string $query, int $limit, string $type = 'title'): array
    {
        $n = max(1, min(200, $limit));
        $attribute = $type === 'artist' ? 'artistTerm' : 'songTerm';
        $url = 'https://itunes.apple.com/search?term=' . urlencode($query)
            . '&entity=song&attribute=' . $attribute
            . '&limit=' . $n
            . '&country=PL';
        [$body, $status] = self::request($url, null);
        if ($status !== 200 || $body === '') {
            self::log("iTunes search FAIL HTTP={$status}");
            return [];
        }
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['results'])) {
            self::log("iTunes search returned 0 results for \"{$query}\"");
            return [];
        }

        $items = [];
        foreach ($data['results'] as $r) {
            if (!is_array($r)) continue;
            $title = trim((string)($r['trackName'] ?? ''));
            $artist = trim((string)($r['artistName'] ?? ''));
            $album = trim((string)($r['collectionName'] ?? 'Singles'));
            if ($title === '' || $artist === '') continue;

            $duration = (int)($r['trackTimeMillis'] ?? 0);
            $trackId = (string)($r['trackId'] ?? '');
            $artwork = (string)($r['artworkUrl100'] ?? '');
            if ($artwork !== '') {
                $artwork = str_replace(['100x100bb', '100x100'], ['600x600bb', '600x600'], $artwork);
            }

            $items[] = [
                'name' => $title,
                'url' => 'itunes:' . $trackId,
                'artists' => [['name' => $artist]],
                'duration_ms' => $duration,
                'album' => [
                    'name' => $album,
                    'images' => $artwork !== '' ? [['url' => $artwork]] : [],
                ],
            ];
        }
        self::log("iTunes search returned " . count($items) . " items for \"{$query}\"");
        return $items;
    }

    private static function searchDeezerArtistDiscography(string $query, int $limit): array
    {
        [$body, $status] = self::request(
            'https://api.deezer.com/search/artist?q=' . urlencode($query) . '&limit=1',
            null
        );
        if ($status !== 200 || $body === '') {
            self::log("Deezer artist lookup FAIL HTTP={$status}");
            return [];
        }
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['data'][0]['id'])) {
            self::log("Deezer artist lookup: no artist found for \"{$query}\"");
            return [];
        }
        $first = $data['data'][0];
        $artistId = (int)$first['id'];
        $artistPic = (string)($first['picture_xl'] ?? $first['picture_big'] ?? $first['picture_medium'] ?? '');
        $artistName = (string)($first['name'] ?? $query);
        self::log("Deezer artist lookup: \"{$query}\" → id={$artistId} name=\"{$artistName}\"");

        $albums = [];
        for ($page = 0; $page < 3; $page++) {
            [$body, $status] = self::request(
                'https://api.deezer.com/artist/' . $artistId . '/albums?limit=100&index=' . ($page * 100),
                null
            );
            if ($status !== 200 || $body === '') break;
            $albData = json_decode($body, true);
            if (!is_array($albData) || empty($albData['data']) || !is_array($albData['data'])) break;
            foreach ($albData['data'] as $a) {
                if (!is_array($a) || empty($a['id'])) continue;
                $albums[(int)$a['id']] = [
                    'id' => (int)$a['id'],
                    'name' => (string)($a['title'] ?? ''),
                    'cover' => (string)($a['cover_xl'] ?? $a['cover_big'] ?? $a['cover_medium'] ?? ''),
                    'release_date' => (string)($a['release_date'] ?? ''),
                    'record_type' => (string)($a['record_type'] ?? ''),
                ];
            }
            if (count($albData['data']) < 100) break;
        }
        self::log("Deezer artist {$artistId}: " . count($albums) . " albums");

        $items = [];
        $count = 0;
        foreach ($albums as $albumId => $albumMeta) {
            if ($count >= $limit) break;
            [$body, $status] = self::request(
                'https://api.deezer.com/album/' . $albumId . '/tracks?limit=100',
                null
            );
            if ($status !== 200 || $body === '') continue;
            $trData = json_decode($body, true);
            if (!is_array($trData) || empty($trData['data']) || !is_array($trData['data'])) continue;

            foreach ($trData['data'] as $r) {
                if (!is_array($r)) continue;
                $title = trim((string)($r['title'] ?? ''));
                $trackArtist = trim((string)($r['artist']['name'] ?? $artistName));
                if ($title === '') continue;

                $duration = (int)($r['duration'] ?? 0);
                $trackId = (string)($r['id'] ?? '');

                $item = [
                    'name' => $title,
                    'url' => 'deezer:' . $trackId,
                    'artists' => [['name' => $trackArtist]],
                    'duration_ms' => $duration * 1000,
                    'album' => [
                        'id' => $albumMeta['id'],
                        'name' => $albumMeta['name'],
                        'release_date' => $albumMeta['release_date'],
                        'record_type' => $albumMeta['record_type'],
                        'images' => $albumMeta['cover'] !== '' ? [['url' => $albumMeta['cover']]] : [],
                    ],
                ];
                if ($artistPic !== '') {
                    $item['artist_picture'] = $artistPic;
                }
                $items[] = $item;
                $count++;
                if ($count >= $limit) break 2;
            }
        }
        self::log("Deezer discography returned " . count($items) . " tracks for \"{$query}\"");
        return $items;
    }

    private static function searchDeezer(string $query, int $limit, string $type = 'title'): array
    {
        $cleanQuery = str_replace(['"', '\\'], '', $query);
        $deezerQuery = $type === 'artist' ? 'artist:"' . $cleanQuery . '"' : 'track:"' . $cleanQuery . '"';

        $items = [];
        $maxPages = (int)ceil(min(9999, $limit) / 100);
        $pageCap = $type === 'artist' ? 100 : 10;
        for ($page = 0; $page < $maxPages && $page < $pageCap; $page++) {
            $url = 'https://api.deezer.com/search?q=' . urlencode($deezerQuery)
                . '&limit=100&index=' . ($page * 100)
                . '&order=RANKING';
            [$body, $status] = self::request($url, null);
            if ($status !== 200 || $body === '') break;
            $data = json_decode($body, true);
            if (!is_array($data) || empty($data['data']) || !is_array($data['data'])) break;

            foreach ($data['data'] as $r) {
                if (!is_array($r)) continue;
                $title = trim((string)($r['title'] ?? ''));
                $artist = trim((string)($r['artist']['name'] ?? ''));
                $album = trim((string)($r['album']['title'] ?? 'Singles'));
                if ($title === '' || $artist === '') continue;

                $duration = (int)($r['duration'] ?? 0);
                $cover = (string)($r['album']['cover_xl'] ?? $r['album']['cover_big'] ?? $r['album']['cover_medium'] ?? '');
                $artistPic = (string)($r['artist']['picture_xl'] ?? $r['artist']['picture_big'] ?? $r['artist']['picture_medium'] ?? '');
                $trackId = (string)($r['id'] ?? '');

                $item = [
                    'name' => $title,
                    'url' => 'deezer:' . $trackId,
                    'artists' => [['name' => $artist]],
                    'duration_ms' => $duration * 1000,
                    'album' => [
                        'name' => $album,
                        'images' => $cover !== '' ? [['url' => $cover]] : [],
                    ],
                ];
                if ($artistPic !== '') {
                    $item['artist_picture'] = $artistPic;
                }
                $items[] = $item;
            }

            if (count($data['data']) < 100) break;
        }

        self::log("Deezer search returned " . count($items) . " items for \"{$query}\"");
        return $items;
    }

    private static function searchYoutube(string $query, int $limit, string $type = 'title'): array
    {
        $bin = YoutubeDownloader::ytDlpBinary();
        $n = max(1, min(100, $limit));
        $effectiveQuery = $type === 'artist' ? $query . ' song' : $query;
        $cmd = escapeshellarg($bin)
            . ' --quiet --no-warnings --flat-playlist --dump-json'
            . ' --default-search ' . escapeshellarg('ytsearch' . $n)
            . ' ' . escapeshellarg('ytsearch' . $n . ':' . $effectiveQuery)
            . ' 2>/dev/null';
        $output = [];
        $rc = 0;
        @exec($cmd, $output, $rc);
        if (empty($output)) {
            self::log("yt-dlp search FAIL rc={$rc}");
            return [];
        }

        $items = [];
        foreach ($output as $line) {
            $data = json_decode($line, true);
            if (!is_array($data) || empty($data['title'])) continue;

            $rawTitle = (string)$data['title'];
            $artist = (string)($data['uploader'] ?? $data['channel'] ?? '');
            $title = $rawTitle;
            if (preg_match('#^(.+?)\s*[-–—]\s*(.+?)(?:\s*\([^)]*\))?$#u', $rawTitle, $m)) {
                $parsedArtist = trim($m[1]);
                $parsedTitle = trim($m[2]);
                $parsedTitle = preg_replace('#\s*\((official|music\s*video|lyric[s]?|audio|hd|hq|video)[^)]*\)\s*$#iu', '', $parsedTitle) ?? $parsedTitle;
                if ($parsedArtist !== '' && $parsedTitle !== '') {
                    $artist = $parsedArtist;
                    $title = trim($parsedTitle);
                }
            }

            $duration = (int)($data['duration'] ?? 0);
            $thumb = (string)($data['thumbnail'] ?? '');
            if ($thumb === '' && !empty($data['thumbnails']) && is_array($data['thumbnails'])) {
                $thumbs = array_values($data['thumbnails']);
                $thumb = (string)($thumbs[count($thumbs) - 1]['url'] ?? '');
            }
            $ytUrl = (string)($data['url'] ?? $data['webpage_url'] ?? '');
            if ($ytUrl !== '' && !str_starts_with($ytUrl, 'http')) {
                $ytUrl = 'https://www.youtube.com/watch?v=' . $ytUrl;
            }
            if ($ytUrl === '') continue;

            $items[] = [
                'name' => $title,
                'url' => $ytUrl,
                'artists' => $artist !== '' ? [['name' => $artist]] : [],
                'duration_ms' => $duration * 1000,
                'album' => [
                    'name' => 'Singles',
                    'images' => $thumb !== '' ? [['url' => $thumb]] : [],
                ],
            ];
        }
        self::log("yt-dlp search returned " . count($items) . " items for \"{$query}\"");
        return $items;
    }

    public static function getTrack(string $id): ?array
    {
        $cacheDir = __DIR__ . '/../../storage/cache/spotify-tracks';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        $cacheFile = $cacheDir . '/' . preg_replace('/[^A-Za-z0-9]/', '', $id) . '.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['name'])) return $cached;
        }

        static $rateLimitedUntil = 0;
        $skipApi = ($rateLimitedUntil > time());

        $token = self::getAccessToken();
        if ($token !== '' && !$skipApi) {
            [$body, $status] = self::request('https://api.spotify.com/v1/tracks/' . urlencode($id) . '?market=PL', $token);
            if ($status === 429) {
                $rateLimitedUntil = time() + 60;
                self::log("Spotify Web API rate limited (429) — skipping API for 60s");
            } elseif ($status === 200 && $body !== '') {
                $data = json_decode($body, true);
                if (is_array($data) && !empty($data['name'])) {
                    @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE));
                    return $data;
                }
            } else {
                self::log("Spotify Web API /v1/tracks HTTP {$status}, falling back to embed");
            }
        }
        $embed = self::getTrackEmbed($id);
        if ($embed !== null) {
            @file_put_contents($cacheFile, json_encode($embed, JSON_UNESCAPED_UNICODE));
            return $embed;
        }
        $oembed = self::getTrackOembed($id);
        if ($oembed !== null) {
            @file_put_contents($cacheFile, json_encode($oembed, JSON_UNESCAPED_UNICODE));
        }
        return $oembed;
    }

    private static function getTrackEmbed(string $id): ?array
    {
        [$body, $status] = self::request('https://open.spotify.com/embed/track/' . urlencode($id), null);
        if ($status !== 200 || $body === '') {
            self::log("embed FAIL HTTP={$status}");
            return null;
        }
        if (!preg_match('#<script id="__NEXT_DATA__"[^>]*>(.*?)</script>#s', $body, $m)) {
            self::log("embed __NEXT_DATA__ not found in HTML");
            return null;
        }
        $data = json_decode($m[1], true);
        if (!is_array($data)) {
            self::log("embed __NEXT_DATA__ JSON parse failed");
            return null;
        }
        $entity = $data['props']['pageProps']['state']['data']['entity']
            ?? $data['props']['pageProps']['entity']
            ?? null;
        if (!is_array($entity)) {
            self::log("embed entity not in expected path");
            return null;
        }

        $title = (string)($entity['title'] ?? $entity['name'] ?? '');
        $artist = (string)($entity['subtitle'] ?? '');
        if ($artist === '' && !empty($entity['artists']) && is_array($entity['artists'])) {
            $first = $entity['artists'][0] ?? null;
            $artist = is_array($first) ? (string)($first['name'] ?? '') : (string)$first;
        }
        if ($title === '') {
            self::log("embed entity has no title");
            return null;
        }

        $cover = '';
        if (!empty($entity['coverArt']['sources']) && is_array($entity['coverArt']['sources'])) {
            $sources = array_values($entity['coverArt']['sources']);
            $cover = (string)($sources[count($sources) - 1]['url'] ?? '');
        }
        $durationMs = (int)($entity['duration'] ?? 0);

        self::log("embed OK title=\"{$title}\" artist=\"{$artist}\" duration_ms={$durationMs}");

        return [
            'id' => $id,
            'name' => $title,
            'artists' => $artist !== '' ? [['name' => $artist]] : [],
            'album' => [
                'name' => 'Singles',
                'images' => $cover !== '' ? [['url' => $cover]] : [],
            ],
            'duration_ms' => $durationMs,
            'external_urls' => ['spotify' => 'https://open.spotify.com/track/' . $id],
        ];
    }

    private static function getTrackOembed(string $id): ?array
    {
        $url = 'https://open.spotify.com/oembed?url=' . urlencode('https://open.spotify.com/track/' . $id);
        [$body, $status] = self::request($url, null);
        if ($status !== 200 || $body === '') {
            self::log("oembed FAIL HTTP={$status} body=" . substr((string)$body, 0, 200));
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['title'])) {
            self::log("oembed parse FAIL body=" . substr($body, 0, 200));
            return null;
        }

        $title = trim((string)$data['title']);
        $artist = '';
        if (preg_match('#^(.+?)\s+(?:by|·|-)\s+(.+)$#iu', $title, $m)) {
            $title = trim($m[1]);
            $artist = trim($m[2]);
        }
        self::log("oembed OK title=\"{$title}\" artist=\"{$artist}\"");

        return [
            'id' => $id,
            'name' => $title,
            'artists' => $artist !== '' ? [['name' => $artist]] : [],
            'album' => [
                'name' => 'Singles',
                'images' => !empty($data['thumbnail_url']) ? [['url' => (string)$data['thumbnail_url']]] : [],
            ],
            'duration_ms' => 0,
            'external_urls' => ['spotify' => 'https://open.spotify.com/track/' . $id],
        ];
    }

    public static function getAccessToken(): string
    {
        if (self::$token !== null && time() < self::$tokenExpiresAt - 30) {
            return self::$token;
        }

        $clientId = (string)Env::get('SPOTIFY_CLIENT_ID', '');
        $clientSecret = (string)Env::get('SPOTIFY_CLIENT_SECRET', '');
        if ($clientId === '' || $clientSecret === '') {
            self::log("Spotify Web API disabled (no SPOTIFY_CLIENT_ID/SECRET in .env)");
            return '';
        }
        if (!function_exists('curl_init')) return '';

        $ch = curl_init('https://accounts.spotify.com/api/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($status !== 200 || !is_string($body)) {
            self::log("Spotify token FAIL HTTP={$status}");
            return '';
        }
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['access_token'])) {
            self::log("Spotify token parse FAIL");
            return '';
        }
        self::$token = (string)$data['access_token'];
        self::$tokenExpiresAt = time() + max(60, (int)($data['expires_in'] ?? 3600));
        self::log("Spotify token OK, expires_in=" . ($data['expires_in'] ?? 0));
        return self::$token;
    }

    private static function request(string $url, ?string $token): array
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
        $accept = 'application/json,text/plain,*/*';
        $lang = 'en-US,en;q=0.9';

        if (!function_exists('curl_init')) {
            $headerStr = "Accept: {$accept}\r\nAccept-Language: {$lang}\r\nUser-Agent: {$ua}\r\n";
            if ($token !== null) $headerStr .= "Authorization: Bearer {$token}\r\n";
            $ctx = stream_context_create(['http' => [
                'header' => $headerStr,
                'timeout' => 6,
                'ignore_errors' => true,
            ]]);
            $body = @file_get_contents($url, false, $ctx);
            $status = 0;
            if (isset($http_response_header[0]) && preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0], $m)) {
                $status = (int)$m[1];
            }
            self::log("HTTP {$status} (stream) {$url}");
            return [$body === false ? '' : $body, $status];
        }
        $ch = curl_init($url);
        $headers = ["Accept: {$accept}", "Accept-Language: {$lang}"];
        if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        unset($ch);
        self::log("HTTP {$status} {$url}" . ($err !== '' ? " err={$err}" : '') . " body=" . substr((string)$body, 0, 200));
        return [$body === false ? '' : $body, $status];
    }

    private static function log(string $msg): void
    {
        $dir = __DIR__ . '/../../storage';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/download.log', '[' . date('Y-m-d H:i:s') . '] [SpotifyApi] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
    }
}
