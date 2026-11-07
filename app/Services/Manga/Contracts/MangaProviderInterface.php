<?php

namespace App\Services\Manga\Contracts;

interface MangaProviderInterface
{
    /**
     * Get a list of manga based on category (popular, trending, ongoing, completed).
     *
     * @param int $page
     * @param int $limit
     * @param string|null $category ('popular', 'trending', 'ongoing', 'completed')
     * @param string|null $genre
     * @return array
     */
    public function getPopular(int $page = 1, int $limit = 24, ?string $category = 'popular', ?string $genre = null): array;

    /**
     * Search manga by query string.
     *
     * @param string $query
     * @param int $page
     * @param int $limit
     * @param string|null $category
     * @param string|null $genre
     * @return array
     */
    public function search(string $query, int $page = 1, int $limit = 24, ?string $category = null, ?string $genre = null): array;

    /**
     * Get detailed information for a specific manga.
     *
     * @param string $id
     * @return array|null
     */
    public function getDetail(string $id): ?array;

    /**
     * Get chapters for a manga.
     *
     * @param string $id
     * @param string|null $language (e.g. 'id', 'en', or null for all)
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getChapters(string $id, ?string $language = null, int $limit = 200, int $offset = 0): array;

    /**
     * Get all pages for a chapter.
     *
     * @param string $chapterId
     * @return array|null
     */
    public function getChapterPages(string $chapterId): ?array;

    /**
     * Proxy an image download with DNS resolution fallback.
     *
     * @param string $url
     * @return array|null ['content' => string, 'contentType' => string]
     */
    public function fetchImage(string $url): ?array;
}
