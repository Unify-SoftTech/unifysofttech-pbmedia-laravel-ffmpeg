<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ProtoneMedia\LaravelFFMpeg\Drivers\PHPFFMpeg;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;
use ProtoneMedia\LaravelFFMpeg\Http\DynamicHLSPlaylist;
use ProtoneMedia\LaravelFFMpeg\MediaOpener;

class HLSPlaylistGenerator implements PlaylistGenerator
{
    const PLAYLIST_START = '#EXTM3U';
    const PLAYLIST_END   = '#EXT-X-ENDLIST';

    /**
     * Extracts the framerate from the given media and formats it in a
     * suitable format.
     *
     * @param \ProtoneMedia\LaravelFFMpeg\MediaOpener $media
     * @return mixed
     */
    private function getFrameRate(MediaOpener $media)
    {
        $mediaStream = $media->getVideoStream();

        $frameRate = trim(Str::before(optional($mediaStream)->get('avg_frame_rate'), "/1"));

        if (!$frameRate || Str::endsWith($frameRate, '/0')) {
            return null;
        }

        if (Str::contains($frameRate, '/')) {
            [$numerator, $denominator] = explode('/', $frameRate);

            $frameRate = $numerator / $denominator;
        }

        return $frameRate ? number_format($frameRate, 3, '.', '') : null;
    }

    /**
     * Return the line from the master playlist that references the given segment playlist.
     *
     * @param \ProtoneMedia\LaravelFFMpeg\Filesystem\Media $playlistMedia
     * @param string $key
     * @return string
     */
    private function getStreamInfoLine(Media $segmentPlaylistMedia, string $key): string
    {
        $segmentPlaylist = $segmentPlaylistMedia->getDisk()->get(
            $segmentPlaylistMedia->getDirectory() . HLSExporter::generateTemporarySegmentPlaylistFilename($key)
        );

        $lines = DynamicHLSPlaylist::parseLines($segmentPlaylist)->filter();

        return $lines->get($lines->search($segmentPlaylistMedia->getFilename()) - 1);
    }

    /**
     * Loops through all segment playlists and generates a main playlist. It finds
     * the relative paths to the segment playlists and adds the framerate when
     * to each playlist.
     *
     * @param array $segmentPlaylists
     * @param \ProtoneMedia\LaravelFFMpeg\Drivers\PHPFFMpeg $driver
     * @return string
     */
    public function get(array $segmentPlaylists, PHPFFMpeg $driver): string
    {
        return Collection::make($segmentPlaylists)->map(function (Media $segmentPlaylist, $key) use ($driver) {
            $streamInfoLine = $this->getStreamInfoLine($segmentPlaylist, $key);

            $media = (new MediaOpener($segmentPlaylist->getDisk(), $driver))
                ->openWithInputOptions($segmentPlaylist->getPath(), ['-allowed_extensions', 'ALL']);

            if ($frameRate = $this->getFrameRate($media)) {
                $streamInfoLine .= ",FRAME-RATE={$frameRate}";
            }

            return [$streamInfoLine, $segmentPlaylist->getFilename()];
        })->collapse()
            ->prepend(static::PLAYLIST_START)
            ->push(static::PLAYLIST_END)
            ->implode(PHP_EOL);
    }
}
