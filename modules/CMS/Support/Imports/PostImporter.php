<?php
/**
 * JUZAWEB CMS - The Best CMS for Laravel Project
 *
 * @package    juzaweb/juzacms
 * @author     Juzaweb Team <admin@juzaweb.com>
 * @link       https://juzaweb.com
 * @license    MIT
 */

namespace Juzaweb\CMS\Support\Imports;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Juzaweb\Backend\Models\Post;
use Juzaweb\Backend\Repositories\TaxonomyRepository;
use Juzaweb\CMS\Contracts\HookActionContract;
use Juzaweb\CMS\Contracts\PostManagerContract;
use Juzaweb\CMS\Contracts\PostImporterContract;
use Juzaweb\CMS\Models\User;
use Juzaweb\CMS\Support\FileManager;

class PostImporter implements PostImporterContract
{
    protected int $createdBy;
    protected bool $downloadThumbnai = true;
    protected bool $downloadContentImages = true;

    public function __construct(
        protected PostManagerContract $postCreator,
        protected HookActionContract $hookAction,
        protected TaxonomyRepository $taxonomyRepository
    ) {
    }

    public function import(array $data, array $options = []): Post
    {
        if (empty($data['type'])) {
            throw new \Exception('Post type is required for import.');
        }

        if ($this->getDownloadThumbnail() && $thumbnail = Arr::get($data, 'thumbnail')) {
            $data['thumbnail'] = $this->addMediaFromUrl($thumbnail, 'image', $options);
        }

        if ($this->getDownloadContentImages()) {
            $data['content'] = $this->saveContentImages($data['content'], $options);
        }

        $taxonomies = $this->hookAction->getTaxonomies($data['type']);

        foreach ($taxonomies as $key => $taxonomy) {
            if ($tax = Arr::get($data, $key)) {
                $data[$key] = $this->getOrCreateTaxonomies($taxonomy, $tax);
                if (empty($data[$key])) {
                    unset($data[$key]);
                }
            }
        }

        return $this->postCreator->create($data);
    }

    public function setDownloadThumbnail(bool $downloadThumbnai): static
    {
        $this->downloadThumbnai = $downloadThumbnai;

        return $this;
    }

    public function getDownloadThumbnail(): bool
    {
        return $this->downloadThumbnai;
    }

    public function setDownloadContentImages(bool $download): static
    {
        $this->downloadContentImages = $download;

        return $this;
    }

    public function getDownloadContentImages(): bool
    {
        return $this->downloadContentImages;
    }

    protected function getOrCreateTaxonomies(Collection $taxonomy, array $data): array
    {
        $ids = [];
        $names = [];
        $slugs = [];

        foreach ($data as $item) {
            if (is_numeric($item)) {
                $ids[] = $item;
                continue;
            }

            $result = [];
            if (is_string($item)) {
                $result['name'] = trim($item);
            } else {
                $result['name'] = trim($item['name']);
            }

            $result['post_type'] = $taxonomy->get('post_type');
            $result['taxonomy'] = $taxonomy->get('taxonomy');
            $result['slug'] = isset($item['slug']) ? trim($item['slug']) : null;

            if (Arr::get($result, 'slug')) {
                $slugs[] = $result;
            } else {
                $names[] = $result;
            }
        }

        $ids = array_merge(
            $ids,
            $this->createTaxonomiesFromNames($names, $taxonomy)
        );

        return array_merge(
            $ids,
            $this->createTaxonomiesFromSlugs($slugs, $taxonomy)
        );
    }

    protected function createTaxonomiesFromSlugs(array $inserts, Collection $taxonomy): array
    {
        $slugs = collect($inserts);

        $taxs = $this->taxonomyRepository->query()
            ->where('post_type', '=', $taxonomy->get('post_type'))
            ->where('taxonomy', '=', $taxonomy->get('taxonomy'))
            ->whereIn('slug', $slugs->pluck('slug')->toArray())
            ->get(['id', 'slug'])
            ->keyBy('slug');

        $slugs = $slugs
            ->filter(
                function ($item) use ($taxs) {
                    return !$taxs->get($item['slug']);
                }
            );

        $ids = [];
        foreach ($slugs as $slug) {
            $ids[] = $this->taxonomyRepository->create($slug)->id;
        }

        return array_merge($taxs->pluck('id')->toArray(), $ids);
    }

    protected function createTaxonomiesFromNames(array $inserts, Collection $taxonomy): array
    {
        $names = collect($inserts);

        $taxs = $this->taxonomyRepository->query()
            ->where('post_type', '=', $taxonomy->get('post_type'))
            ->where('taxonomy', '=', $taxonomy->get('taxonomy'))
            ->whereIn('name', $names->pluck('name')->toArray())
            ->get(['id', 'name'])
            ->keyBy('name');

        $names = $names
            ->filter(
                function ($item) use ($taxs) {
                    return !$taxs->get($item['name']);
                }
            )->map(
                function ($item) {
                    $item['name'] = trim($item['name']);
                    return $item;
                }
            );

        $ids = [];
        foreach ($names as $name) {
            $ids[] = $this->taxonomyRepository->create($name)->id;
        }

        return array_merge($taxs->pluck('id')->toArray(), $ids);
    }

    protected function saveContentImages($content, array $options)
    {
        $html = str_get_html($content);

        $imgs = $html->find('img');

        if (empty($imgs)) {
            return $content;
        }

        $urls = [];
        foreach ($imgs as $e) {
            $url = $e->src ?? $e->{'data-src'};
            if ($url) {
                $urls[] = $url;
            }
        }

        $urls = array_unique($urls);
        foreach ($urls as $url) {
            $image = $this->addMediaFromUrl(trim($url), 'image', $options);

            if (empty($image)) {
                continue;
            }

            $content = str_replace(
                $url,
                $image,
                $content
            );
        }

        return $content;
    }

    protected function addMediaFromUrl(
        string $url,
        string $type = 'image',
        array $options = []
    ): string {
        $createUserId = $options['created_by'] ?? User::where(['is_admin' => 1])->first()->id;

        $file = FileManager::addFile(
            $url,
            $type,
            $options['media_folder'] ?? null,
            $createUserId
        );

        return $file->path;
    }
}
