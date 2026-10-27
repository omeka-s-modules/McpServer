<?php
namespace McpServer\Mcp\Tool\Builtin;

use McpServer\Mcp\Format\JsonLdFormatter;
use McpServer\Mcp\Format\OutputLimits;
use McpServer\Mcp\Format\ResourceFormatter;
use McpServer\Mcp\Format\ValueFormatter;
use McpServer\Mcp\Tool\ArgumentReader;
use McpServer\Mcp\Tool\ToolException;
use McpServer\Mcp\Tool\ToolResult;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\File\Store\Local;
use Omeka\File\Store\StoreInterface;
use Omeka\File\ThumbnailManager;

/**
 * Reads one media, with its image when it has a thumbnail.
 *
 * It reads like get_item, and adds the image and an HTML media's text, which
 * "content" reads.
 */
class GetMedia extends AbstractGetTool
{
    // Largest image file sent. A large thumbnail is usually far smaller.
    const IMAGE_BYTES_MAX = 2000000;

    // Thumbnail types to send, in order of preference.
    const THUMBNAIL_TYPES = ['large', 'medium'];

    protected $store;

    protected $thumbnails;

    public function __construct(ApiManager $api, ResourceFormatter $formatter, ValueFormatter $valueFormatter, JsonLdFormatter $jsonLdFormatter, StoreInterface $store, ThumbnailManager $thumbnails)
    {
        parent::__construct($api, $formatter, $valueFormatter, $jsonLdFormatter);
        $this->store = $store;
        $this->thumbnails = $thumbnails;
    }

    public function getTitle(): string
    {
        return 'Get media';
    }

    public function getDescription(): string
    {
        return 'Get one media by its id, as given by get_item or search_media: its metadata, the item it '
            . 'belongs to, links to its file, in data what it was made from, such as an embedded video\'s title '
            . 'and author, and in module_data whatever other modules add. When the media has a thumbnail, as images and usually PDFs '
            . 'and videos do, the result includes that image, up to 800 pixels on its longer side, so you can '
            . 'see what the media shows. Describe only what the image shows, and say when a detail would need '
            . 'the original file. The default response_format, "concise", suits almost every question; use '
            . '"detailed" only when you need Omeka\'s raw JSON-LD.';
    }

    protected function getResourceName(): string
    {
        return 'media';
    }

    protected function getSingularNoun(): string
    {
        return 'media';
    }

    protected function getConcise(AbstractResourceEntityRepresentation $media): array
    {
        return $this->formatter->media($media);
    }

    protected function getOwnSchema(): array
    {
        return [
            'image' => [
                'type' => 'boolean',
                'default' => true,
                'description' => 'Include the image. Turn it off to read only the metadata.',
            ],
            'content' => [
                'type' => 'boolean',
                'description' => sprintf(
                    'Read this media\'s own text, as an HTML media holds it, instead of its record: %s characters at a time from "offset".',
                    number_format(OutputLimits::CHUNK_LIMIT)
                ),
            ],
        ];
    }

    /**
     * Read an HTML media's text, when "content" asks for it.
     */
    protected function readOwn(AbstractResourceEntityRepresentation $media, ArgumentReader $reader): ?ToolResult
    {
        if (!$reader->getBool('content', false)) {
            return null;
        }
        if ($reader->has('property') || 'detailed' === $reader->getEnum('response_format', self::FORMATS, 'concise')) {
            throw new ToolException('"content" reads this media\'s own text, so it doesn\'t take "property" or "response_format" "detailed".');
        }
        return $this->readContent($media, $reader);
    }

    /**
     * Read part of a media's text content, from an offset.
     */
    protected function readContent(MediaRepresentation $media, ArgumentReader $reader): ToolResult
    {
        $this->refusePage($reader);
        // A position of 1, the default, is allowed, since models often send
        // defaults.
        if (1 !== $reader->getInt('position', 1, 1)) {
            throw new ToolException('"content" is a single text, so it takes "offset" but not "position".');
        }
        $offset = $reader->getInt('offset', 0, 0);
        $chunk = $this->formatter->contentChunk($media, $offset);
        if (null === $chunk) {
            throw new ToolException('This media has no text content. Only an HTML media holds its text itself, rather than in a file.');
        }
        $output = [
            'id' => $media->id(),
            'title' => $media->displayTitle(),
        ];
        return $this->getChunkResult($output, $chunk, $offset);
    }

    /**
     * Get the media's thumbnail, unless "image" is false, with a note when
     * there's none.
     */
    protected function getImage(AbstractResourceEntityRepresentation $media, ArgumentReader $reader): array
    {
        if (!$reader->getBool('image', true)) {
            return [null, null];
        }
        $image = $this->getThumbnail($media);
        if (null !== $image) {
            return [$image, null];
        }
        return [null, $media->originalUrl()
            ? 'No image of this media is available here. original_url links to its file.'
            : 'This media has no image or file to show.'];
    }

    /**
     * Get the media's thumbnail as JPEG bytes, or null when there's none to
     * send.
     *
     * The first of THUMBNAIL_TYPES that exists, can be read, and is no larger
     * than IMAGE_BYTES_MAX. Read from local storage only: other stores would
     * need the file fetched over HTTP.
     */
    protected function getThumbnail(MediaRepresentation $media): ?string
    {
        if (!$media->hasThumbnails() || !$this->store instanceof Local) {
            return null;
        }
        foreach (self::THUMBNAIL_TYPES as $type) {
            if (!$this->thumbnails->typeExists($type)) {
                continue;
            }
            $path = $this->store->getLocalPath(sprintf('%s/%s.jpg', $type, $media->storageId()));
            if (is_file($path) && is_readable($path) && filesize($path) <= self::IMAGE_BYTES_MAX) {
                $bytes = file_get_contents($path);
                return false === $bytes ? null : $bytes;
            }
        }
        return null;
    }
}
