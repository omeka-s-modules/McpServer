<?php
namespace McpServer\Mcp\Tool\Builtin;

use McpServer\Mcp\Exception\ToolException;
use McpServer\Mcp\Format\ResourceFormatter;
use McpServer\Mcp\Format\ValueFormatter;
use McpServer\Mcp\Tool\ArgumentReader;
use McpServer\Mcp\Tool\Context;
use McpServer\Mcp\Tool\ToolAnnotations;
use McpServer\Mcp\Tool\ToolInterface;
use McpServer\Mcp\Tool\ToolResult;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\File\Store\Local;
use Omeka\File\Store\StoreInterface;
use Omeka\File\ThumbnailManager;

/**
 * Reads one media, with its image when it has a thumbnail.
 *
 * It doesn't extend AbstractGetTool, since a media has no detailed format, and
 * its result can carry an image. It reads one field in full the same way,
 * through ReadsFieldsTrait.
 */
class GetMedia implements ToolInterface
{
    use ReadsFieldsTrait;

    // Largest image file sent. A large thumbnail is usually far smaller.
    const IMAGE_BYTES_MAX = 2000000;

    // Thumbnail types to send, in order of preference.
    const THUMBNAIL_TYPES = ['large', 'medium'];

    protected $api;

    protected $formatter;

    protected $valueFormatter;

    protected $store;

    protected $thumbnails;

    public function __construct(ApiManager $api, ResourceFormatter $formatter, ValueFormatter $valueFormatter, StoreInterface $store, ThumbnailManager $thumbnails)
    {
        $this->api = $api;
        $this->formatter = $formatter;
        $this->valueFormatter = $valueFormatter;
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
            . 'belongs to, and links to its file. When the media has a thumbnail, as images and usually PDFs '
            . 'and videos do, the result includes that image, up to 800 pixels on its longer side, so you can '
            . 'see what the media shows. Describe only what the image shows, and say when a detail would need '
            . 'the original file.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The media\'s id.',
                ],
                'image' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'Include the image. Turn it off to read only the metadata.',
                ],
            ] + $this->getFieldSchema(true),
            'required' => ['id'],
        ];
    }

    public function getAnnotations(): array
    {
        return ToolAnnotations::READ_ONLY;
    }

    public function call(array $arguments, Context $context): ToolResult
    {
        $reader = new ArgumentReader($arguments);
        $id = $reader->requireInt('id', 1);
        try {
            $media = $this->api->read('media', $id)->getContent();
        } catch (NotFoundException $e) {
            throw new ToolException(sprintf('No media with id %d exists or is visible to you.', $id));
        }
        $field = $this->readField($media, $reader, 'media');
        if ($field) {
            return $field;
        }
        $concise = $this->formatter->media($media);
        $notes = array_filter([OutputLimitNotes::forConcise($concise)]);
        $image = $reader->getBool('image', true) ? $this->getThumbnail($media) : null;
        if ($reader->getBool('image', true) && null === $image) {
            $notes[] = isset($concise['original_url'])
                ? 'No image of this media is available here. original_url links to its file.'
                : 'This media has no image or file to show.';
        }
        $result = ToolResult::json($concise, $notes ? implode(' ', $notes) : null);
        return $image ? $result->withImage($image, 'image/jpeg') : $result;
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

    protected function getFormatter(): ResourceFormatter
    {
        return $this->formatter;
    }

    protected function getValueFormatter(): ValueFormatter
    {
        return $this->valueFormatter;
    }
}
