<?php

namespace Ekumanov\DiscussionOgMeta;

use Flarum\Frontend\Document;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface as Request;

class AddOgMetaTags
{
    private const MAX_DESCRIPTION_LENGTH = 200;

    public function __construct(
        private readonly SettingsRepositoryInterface $settings
    ) {
    }

    public function __invoke(Document $document, Request $request): void
    {
        $apiDocument = $document->payload['apiDocument'] ?? null;

        if (! $apiDocument || ! is_object($apiDocument)) {
            return;
        }

        $data = $apiDocument->data ?? null;

        if (! $data || ($data->type ?? null) !== 'discussions') {
            return;
        }

        $attributes = $data->attributes ?? null;

        if (! $attributes) {
            return;
        }

        if (! $this->isPublicallyShareable($attributes)) {
            return;
        }

        $title = trim((string) ($attributes->title ?? ''));
        $url = (string) ($document->canonicalUrl ?? '');

        if ($title === '' || $url === '') {
            return;
        }

        $siteName = trim((string) $this->settings->get('forum_title'));
        $firstPost = $this->findFirstPost($apiDocument, $data);
        $description = $this->buildDescription($firstPost);
        $imageUrl = $this->findFirstImageUrl($firstPost);

        $tags = $this->buildTags($title, $description, $url, $siteName, $imageUrl);

        $document->head = array_merge($document->head, $tags);
    }

    private function isPublicallyShareable(object $attributes): bool
    {
        if (($attributes->isHidden ?? false) === true) {
            return false;
        }

        if (($attributes->isPrivateDiscussion ?? false) === true) {
            return false;
        }

        if (property_exists($attributes, 'isApproved') && $attributes->isApproved === false) {
            return false;
        }

        return true;
    }

    private function findFirstPost(object $apiDocument, object $data): ?object
    {
        $firstPostRef = $data->relationships->firstPost->data ?? null;
        $included = $apiDocument->included ?? [];

        if (! $firstPostRef || ! is_array($included)) {
            return null;
        }

        foreach ($included as $resource) {
            if (($resource->type ?? null) === 'posts' && ($resource->id ?? null) === ($firstPostRef->id ?? null)) {
                return $resource;
            }
        }

        return null;
    }

    private function buildDescription(?object $firstPost): string
    {
        $contentHtml = $firstPost?->attributes?->contentHtml ?? '';

        if (! is_string($contentHtml) || $contentHtml === '') {
            return '';
        }

        // Replace every HTML tag with a single space (instead of strip_tags' empty
        // string) so that block-level boundaries like </p><p> don't concatenate
        // their text. The whitespace collapse below cleans up the extra spaces.
        $text = (string) preg_replace('~<[^>]+>~', ' ', $contentHtml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) <= self::MAX_DESCRIPTION_LENGTH) {
            return $text;
        }

        $truncated = mb_substr($text, 0, self::MAX_DESCRIPTION_LENGTH);
        $lastSpace = mb_strrpos($truncated, ' ');

        if ($lastSpace !== false && $lastSpace > self::MAX_DESCRIPTION_LENGTH * 0.6) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return rtrim($truncated, " \t\n\r\0\x0B.,;:!?-").'…';
    }

    private function findFirstImageUrl(?object $firstPost): ?string
    {
        $contentHtml = $firstPost?->attributes?->contentHtml ?? '';

        if (! is_string($contentHtml) || $contentHtml === '' || ! str_contains($contentHtml, '<img')) {
            return null;
        }

        if (! preg_match_all('~<img\b[^>]*>~i', $contentHtml, $matches)) {
            return null;
        }

        foreach ($matches[0] as $imgTag) {
            if (preg_match('~class="[^"]*\bemoji\b[^"]*"~i', $imgTag)) {
                continue;
            }

            if (! preg_match('~\bsrc="([^"]+)"~i', $imgTag, $srcMatch)) {
                continue;
            }

            $src = html_entity_decode($srcMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($src === '' || str_starts_with($src, 'data:')) {
                continue;
            }

            if (stripos($src, '/emoji/') !== false || stripos($src, '/emojis/') !== false) {
                continue;
            }

            return $src;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function buildTags(string $title, string $description, string $url, string $siteName, ?string $imageUrl): array
    {
        $ogTitle = $siteName !== '' ? "$title - $siteName" : $title;
        $twitterCard = $imageUrl !== null ? 'summary_large_image' : 'summary';

        $tags = [];
        $tags[] = $this->ogTag('og:title', $ogTitle);
        $tags[] = $this->ogTag('og:type', 'article');
        $tags[] = $this->ogTag('og:url', $url);

        if ($siteName !== '') {
            $tags[] = $this->ogTag('og:site_name', $siteName);
        }

        if ($description !== '') {
            $tags[] = $this->ogTag('og:description', $description);
        }

        if ($imageUrl !== null) {
            $tags[] = $this->ogTag('og:image', $imageUrl);
        }

        $tags[] = $this->nameTag('twitter:card', $twitterCard);
        $tags[] = $this->nameTag('twitter:title', $ogTitle);

        if ($description !== '') {
            $tags[] = $this->nameTag('twitter:description', $description);
        }

        if ($imageUrl !== null) {
            $tags[] = $this->nameTag('twitter:image', $imageUrl);
        }

        return $tags;
    }

    private function ogTag(string $property, string $content): string
    {
        return '<meta property="'.e($property).'" content="'.e($content).'">';
    }

    private function nameTag(string $name, string $content): string
    {
        return '<meta name="'.e($name).'" content="'.e($content).'">';
    }
}
