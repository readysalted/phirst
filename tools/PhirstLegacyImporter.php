<?php

/**
 * Offline, idempotent conversion of the legacy PHIRST WXR into Flynt fields.
 * Source exports, HTML, media caches and reports stay outside the repository.
 */

namespace Flynt\Migration;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use WP_CLI;

class PhirstLegacyImporter
{
    private array $source;
    private array $cache;
    private array $api = [];
    private array $items = [];
    private array $postMap = [];
    private array $urlMap = [];
    private array $attachmentMap = [];
    private array $attachmentUrls = [];
    private array $mediaByUrl = [];
    private array $terms = [];
    private array $report = ['pages' => 0, 'evaluations' => 0, 'posts' => 0, 'people' => 0, 'media' => 0, 'themes' => 0, 'warnings' => []];
    private array $homeBefore;
    private int $home;
    private int $headerImage;

    public function __construct(private string $directory, private bool $replaceComponents = false)
    {
        $this->source = json_decode(file_get_contents($directory . '/legacy-wxr.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->cache = json_decode(file_get_contents($directory . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->home = (int) get_option('page_on_front');
        $this->homeBefore = get_field('pageComponents', $this->home, false) ?: [];
        foreach (array_merge($this->cache['pages'], $this->cache['posts']) as $item) {
            $this->api[$item['id']] = $item;
        }
        foreach ($this->source['items'] as $item) {
            $this->items[$item['id']] = $item;
        }
        foreach ($this->source['attachments'] as $id => $attachment) {
            $url = $this->normalizeUrl($attachment['attachment_url']);
            $this->attachmentUrls[$url] = (int) $id;
            $metadata = $this->decode($attachment['meta']['_wp_attachment_metadata'] ?? '');
            if (is_array($metadata)) {
                foreach ($metadata['sizes'] ?? [] as $size) {
                    $this->attachmentUrls[dirname($url) . '/' . $size['file']] = (int) $id;
                }
            }
        }
        foreach (get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1]) as $attachment) {
            $id = $attachment->ID;
            $legacyId = (int) get_post_meta($id, '_phirst_legacy_attachment_id', true);
            if ($legacyId) {
                $this->attachmentMap[$legacyId] = $id;
            }
            foreach (['_phirst_legacy_media_url', '_source_url', '_phirst_partner_source'] as $key) {
                $url = get_post_meta($id, $key, true);
                if ($url) {
                    $this->mediaByUrl[$this->normalizeUrl($url)] = $id;
                }
            }
        }
    }

    private function decode(string $value): mixed
    {
        return is_serialized($value) ? unserialize(trim($value), ['allowed_classes' => false]) : $value;
    }

    private function normalizeUrl(string $url): string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        return preg_replace('#^http://phirst\.nihr\.ac\.uk/#', 'https://phirst.nihr.ac.uk/', $url);
    }

    private function plain(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function textRows(string $html, string $theme = 'white'): array
    {
        if (!$this->plain($html) && !preg_match('/<(img|iframe|video|audio)\b/i', $html)) {
            return [];
        }
        $document = $this->document('<div id="phirst-source-fragment">' . $html . '</div>');
        $root = $document->getElementById('phirst-source-fragment');
        if (!$root) {
            return [];
        }
        $rows = [];
        $buffer = '';
        $flush = function () use (&$rows, &$buffer, $theme): void {
            $clean = $this->cleanHtml($buffer);
            if ($this->plain($clean) || str_contains($clean, '<img')) {
                $rows[] = $this->wysiwyg($clean, $theme);
            }
            $buffer = '';
        };
        foreach (iterator_to_array($root->childNodes) as $node) {
            if ($node instanceof DOMElement && in_array($node->tagName, ['h2', 'h1'], true)) {
                $flush();
            }
            $nodeHtml = $document->saveHTML($node);
            $isEmbed = $node instanceof DOMElement && (
                str_contains($node->getAttribute('class'), 'wp-block-embed') || $node->tagName === 'iframe'
            );
            if ($isEmbed && preg_match('#https?://(?:www\.)?(?:youtube(?:-nocookie)?\.com/[^\s"<>]+|youtu\.be/[^\s"<>]+|vimeo\.com/[^\s"<>]+)#i', $nodeHtml, $match)) {
                $flush();
                $url = html_entity_decode($match[0], ENT_QUOTES, 'UTF-8');
                if (preg_match('#/(?:embed/|shorts/|live/)([a-zA-Z0-9_-]{11})#', $url, $youtube)) {
                    $url = 'https://www.youtube.com/watch?v=' . $youtube[1];
                }
                $rows[] = ['acf_fc_layout' => 'blockVideoOembed', 'oembed' => $url, 'videoUrl' => $url, 'options' => ['size' => 'medium', 'theme' => $theme]];
            } else {
                $buffer .= $nodeHtml;
            }
        }
        $flush();
        return $rows;
    }

    private function document(string $html): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $document;
    }

    private function innerHtml(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }
        return $html;
    }

    private function cleanHtml(string $html): string
    {
        $html = preg_replace('/\[(?:everest_form|tpsearch)\b[^\]]*\]/i', '', $html);
        $html = preg_replace_callback('/\[video\b([^\]]*)\](?:.*?)\[\/video\]/si', function (array $match): string {
            if (preg_match('/mp4=[\"\']([^\"\']+)[\"\']/', $match[1], $video)) {
                return '<video controls preload="none" playsinline><source src="' . esc_url($video[1]) . '" type="video/mp4"></video>';
            }
            return '';
        }, $html);
        $document = $this->document('<div id="phirst-clean">' . $html . '</div>');
        $root = $document->getElementById('phirst-clean');
        if (!$root) {
            return '';
        }
        $xpath = new DOMXPath($document);
        foreach (iterator_to_array($xpath->query('//script|//style|//form|//input|//button|//comment()|//*[@data-mce-type="bookmark"]')) as $node) {
            $node->parentNode?->removeChild($node);
        }
        foreach (iterator_to_array($xpath->query('//iframe')) as $iframe) {
            $link = $document->createElement('a', __('Watch video', 'flynt'));
            $link->setAttribute('href', $iframe->getAttribute('src'));
            $iframe->parentNode->replaceChild($link, $iframe);
        }
        foreach (iterator_to_array($root->getElementsByTagName('*')) as $element) {
            if ($element->tagName === 'h1') {
                $heading = $document->createElement('h2');
                while ($element->firstChild) {
                    $heading->appendChild($element->firstChild);
                }
                $element->parentNode->replaceChild($heading, $element);
                $element = $heading;
            }
            foreach (iterator_to_array($element->attributes) as $attribute) {
                if (in_array($attribute->name, ['style', 'class', 'srcset', 'sizes'], true) || str_starts_with($attribute->name, 'on')) {
                    $element->removeAttribute($attribute->name);
                }
            }
            if (str_starts_with($element->getAttribute('id'), 'http')) {
                $element->removeAttribute('id');
            }
            foreach (['href', 'src'] as $attribute) {
                if ($element->hasAttribute($attribute)) {
                    $element->setAttribute($attribute, $this->rewriteUrl($element->getAttribute($attribute)));
                }
            }
            if ($element->tagName === 'img') {
                $element->setAttribute('loading', 'lazy');
                $element->setAttribute('decoding', 'async');
            }
            if ($element->tagName === 'a' && $element->getAttribute('target') === '_blank') {
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }
        return trim(wp_kses_post($this->innerHtml($root)));
    }

    private function wysiwyg(string $html, string $theme = 'white', string $style = 'phirstArticle'): array
    {
        return [
            'acf_fc_layout' => 'blockWysiwyg',
            'contentHtml' => $html,
            'options' => ['displayStyle' => $style, 'theme' => $theme, 'size' => 'medium', 'align' => 'center', 'textAlign' => 'left'],
        ];
    }

    private function rewriteUrl(string $url): string
    {
        $url = $this->normalizeUrl($url);
        if (!str_contains($url, 'phirst.nihr.ac.uk')) {
            return $url;
        }
        if (str_contains($url, '/wp-content/uploads/')) {
            $id = $this->media($url);
            return $id ? wp_get_attachment_url($id) : $url;
        }
        $parts = wp_parse_url($url);
        $path = rawurldecode($parts['path'] ?? '/');
        $normalizedPath = '/' . trim(preg_replace('#/+#', '/', $path), '/') . '/';
        if ($normalizedPath === '//') {
            $normalizedPath = '/';
        }
        if (isset($this->urlMap[$normalizedPath])) {
            return $this->urlMap[$normalizedPath] . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
        }
        if (preg_match('#^/tag/([^/]+)/#', $normalizedPath, $tag)) {
            $term = get_term_by('slug', $tag[1], 'post_tag');
            if ($term) {
                return get_term_link($term);
            }
        }
        return $url;
    }

    private function media(string|int $reference): int
    {
        if (!$reference || (is_numeric($reference) && (int) $reference <= 0)) {
            return 0;
        }
        $legacyId = is_numeric($reference) ? (int) $reference : 0;
        $attachment = $legacyId ? ($this->source['attachments'][(string) $legacyId] ?? null) : null;
        $url = $this->normalizeUrl($attachment['attachment_url'] ?? (string) $reference);
        $legacyId = $legacyId ?: ($this->attachmentUrls[$url] ?? 0);
        if ($legacyId && isset($this->attachmentMap[$legacyId])) {
            return $this->attachmentMap[$legacyId];
        }
        if (isset($this->mediaByUrl[$url])) {
            return $this->mediaByUrl[$url];
        }
        if ($legacyId) {
            $attachment = $this->source['attachments'][(string) $legacyId] ?? null;
            $url = $this->normalizeUrl($attachment['attachment_url'] ?? $url);
        }
        $spec = $this->cache['assets'][$url] ?? null;
        if (!$spec && $legacyId) {
            foreach ($this->attachmentUrls as $variant => $originalId) {
                if ($originalId === $legacyId && isset($this->cache['assets'][$variant])) {
                    $spec = $this->cache['assets'][$variant];
                    break;
                }
            }
        }
        if (!$spec || !is_file($this->directory . '/' . $spec['file'])) {
            $this->report['warnings']['missing-media:' . $url] = 'Source asset not cached: ' . $url;
            return 0;
        }
        $id = $this->insertFile($this->directory . '/' . $spec['file'], $spec['filename'], $attachment['title'] ?? $spec['filename']);
        $this->mediaByUrl[$url] = $id;
        if ($legacyId) {
            $this->attachmentMap[$legacyId] = $id;
            update_post_meta($id, '_phirst_legacy_attachment_id', $legacyId);
        }
        update_post_meta($id, '_phirst_legacy_media_url', $url);
        $alt = $attachment['meta']['_wp_attachment_image_alt'] ?? '';
        if ($alt) {
            update_post_meta($id, '_wp_attachment_image_alt', $alt);
        }
        $this->report['media']++;
        return $id;
    }

    private function insertFile(string $source, string $filename, string $title): int
    {
        $uploads = wp_upload_dir();
        if ($uploads['error']) {
            WP_CLI::error($uploads['error']);
        }
        $filename = wp_unique_filename($uploads['path'], sanitize_file_name($filename));
        $destination = $uploads['path'] . '/' . $filename;
        if (!copy($source, $destination)) {
            WP_CLI::error('Cannot copy media: ' . $filename);
        }
        $type = wp_check_filetype($filename, array_merge(get_allowed_mime_types(), ['svg' => 'image/svg+xml']));
        if (!$type['type']) {
            WP_CLI::error('Unsupported source file type: ' . $filename);
        }
        $id = wp_insert_attachment([
            'post_title' => $title, 'post_status' => 'inherit', 'post_mime_type' => $type['type'],
        ], $destination, 0, true);
        if (is_wp_error($id)) {
            WP_CLI::error($id->get_error_message());
        }
        $metadata = ['file' => _wp_relative_upload_path($destination)];
        if ($type['type'] === 'image/svg+xml') {
            $svg = new DOMDocument();
            if (@$svg->load($destination, LIBXML_NONET)) {
                $root = $svg->documentElement;
                $viewBox = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')));
                $metadata['width'] = (int) ($viewBox[2] ?? (float) $root->getAttribute('width')) ?: 280;
                $metadata['height'] = (int) ($viewBox[3] ?? (float) $root->getAttribute('height')) ?: 100;
            }
        } elseif (str_starts_with($type['type'], 'image/')) {
            $size = @getimagesize($destination);
            if ($size) {
                $metadata['width'] = $size[0];
                $metadata['height'] = $size[1];
                if ($size[0] > 768 && $type['type'] !== 'image/gif') {
                    $editor = wp_get_image_editor($destination);
                    if (!is_wp_error($editor)) {
                        $editor->resize(768, null, false);
                        $resized = $editor->save();
                        if (!is_wp_error($resized)) {
                            $metadata['sizes']['medium_large'] = [
                                'file' => basename($resized['path']), 'width' => $resized['width'],
                                'height' => $resized['height'], 'mime-type' => $resized['mime-type'],
                            ];
                        }
                    }
                }
            }
        }
        wp_update_attachment_metadata($id, $metadata);
        return (int) $id;
    }

    private function designImage(string $file, string $alt): int
    {
        $existing = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1,
            'meta_key' => '_phirst_design_asset', 'meta_value' => 'evaluations/' . $file, 'fields' => 'ids']);
        if ($existing) {
            return (int) $existing[0];
        }
        $id = $this->insertFile(get_stylesheet_directory() . '/assets/images/phirst/evaluations/' . $file, $file, $alt);
        update_post_meta($id, '_phirst_design_asset', 'evaluations/' . $file);
        update_post_meta($id, '_wp_attachment_image_alt', $alt);
        return $id;
    }

    private function term(array $term, string $taxonomy): int
    {
        $slug = $term['slug'];
        $name = $term['name'];
        if ($taxonomy === 'evaluation_theme' && $slug === 'digitial-and-online-initiatives') {
            $slug = 'digital-and-online-initiatives';
            $name = 'Digital and online initiatives';
        }
        $key = $taxonomy . ':' . $slug;
        if (!isset($this->terms[$key])) {
            $existing = term_exists($slug, $taxonomy);
            if (!$existing) {
                $existing = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
            }
            if (is_wp_error($existing)) {
                WP_CLI::error($existing->get_error_message());
            }
            $this->terms[$key] = (int) (is_array($existing) ? $existing['term_id'] : $existing);
        }
        return $this->terms[$key];
    }

    private function hero(string $title, int $image = 0): array
    {
        return ['acf_fc_layout' => 'BlockHero', 'title' => $title, 'image' => $image ?: $this->headerImage, 'displayStyle' => 'phirstInterior'];
    }

    private function grid(string $source = 'manual', array $items = [], string $heading = ''): array
    {
        return ['acf_fc_layout' => 'gridImageText', 'dataSource' => $source, 'items' => $items, 'preContentHtml' => $heading,
            'options' => ['displayStyle' => 'phirstTiles', 'theme' => 'white', 'maxColumns' => 3, 'card' => 1]];
    }

    private function sourceLink(array $link): array
    {
        return ['title' => $this->plain($link['title'] ?? ''), 'url' => $this->rewriteUrl($link['url'] ?? ''), 'target' => $link['target'] ?? ''];
    }

    private function signupRow(string $html): array
    {
        $document = $this->document($html);
        $xpath = new DOMXPath($document);
        $form = $xpath->query('//form[contains(@action,"list-manage.com")]')->item(0);
        if (!$form) {
            return [];
        }
        $fields = [];
        $consentName = '';
        $honeypotName = '';
        foreach ($xpath->query('.//input|.//select', $form) as $input) {
            $type = $input->tagName === 'select' ? 'select' : $input->getAttribute('type');
            $name = $input->getAttribute('name');
            if (str_starts_with($name, 'gdpr[')) {
                $consentName = $name;
            } elseif (str_starts_with($name, 'b_')) {
                $honeypotName = $name;
            } elseif (in_array($type, ['email', 'text', 'select'], true) && $name) {
                $label = $xpath->query('.//label[@for="' . $input->getAttribute('id') . '"]', $form)->item(0);
                $choices = [];
                foreach ($input->getElementsByTagName('option') as $option) {
                    if ($option->getAttribute('value')) {
                        $choices[] = $this->plain($option->textContent);
                    }
                }
                $fields[] = ['name' => $name, 'label' => trim(str_replace('*', '', $this->plain($label?->textContent ?? $name))),
                    'type' => $type, 'required' => $input->hasAttribute('required') ? 1 : 0, 'choicesText' => implode("\n", $choices)];
            }
        }
        $privacy = $xpath->query('.//*[contains(@class,"content__gdprBlock")]', $form)->item(0);
        if ($privacy) {
            foreach (iterator_to_array($xpath->query('.//fieldset|.//label', $privacy)) as $obsolete) {
                $obsolete->parentNode?->removeChild($obsolete);
            }
        }
        $privacyHtml = $privacy ? $this->innerHtml($privacy) : '';
        return ['acf_fc_layout' => 'blockMailingList', 'contentHtml' => '<h2>Subscribe</h2><p>Keep up to date with all our latest news – complete the form below:</p>', 'signupForm' => [
            'action' => $form->getAttribute('action'), 'fields' => $fields, 'honeypotName' => $honeypotName,
            'consentName' => $consentName, 'consentLabel' => 'I consent to receive PHIRST communications by email.',
            'consentHtml' => $this->cleanHtml($privacyHtml),
        ], 'options' => ['displayStyle' => 'signupForm', 'theme' => 'white']];
    }

    private function buildRows(array $item): array
    {
        $meta = $item['meta'];
        $title = $this->plain(($meta['custom_page_title'] ?? '') ?: $item['title']);
        $featured = get_post_thumbnail_id($this->postMap[$item['id']]);
        $rows = [$this->hero($title, $featured ?: $this->headerImage)];
        $body = $this->api[$item['id']]['content']['rendered'] ?? wpautop($item['content']);
        $isEvaluation = ($meta['_wp_page_template'] ?? '') === 'templates/evaluating.php';

        if ($item['slug'] === 'newsletter-sign-up') {
            $row = $this->signupRow($item['content']);
            return $row ? array_merge($rows, [$row]) : array_merge($rows, $this->textRows($body));
        }

        if (!empty($meta['short_description'])) {
            array_push($rows, ...$this->textRows(wpautop($meta['short_description'])));
        }
        if ($isEvaluation) {
            $research = [];
            for ($index = 0; $index < (int) ($meta['research_research'] ?? 0); $index++) {
                $html = wpautop($meta['research_research_' . $index . '_text'] ?? '');
                if (preg_match('/^(Expected completion|Evaluation complete|Evaluation in progress|Completed|In progress)/i', $this->plain($html))) {
                    $status = $this->plain($html);
                    update_field('evaluationStatusText', $status, $this->postMap[$item['id']]);
                    $rows[] = $this->wysiwyg('<p><strong>' . esc_html($status) . '</strong></p>', 'pink-quartz', 'phirstStatus');
                } else {
                    $research[] = $html;
                }
            }
            $panels = [];
            for ($index = 0; $index < (int) ($meta['accordion'] ?? 0); $index++) {
                $panels[] = ['title' => $this->plain($meta['accordion_' . $index . '_output'] ?? ''),
                    'contentHtml' => $this->cleanHtml(wpautop($meta['accordion_' . $index . '_input'] ?? ''))];
            }
            if ($panels) {
                $rows[] = ['acf_fc_layout' => 'accordionDefault', 'accordionPanels' => $panels,
                    'options' => ['displayStyle' => 'phirstOutputs', 'theme' => 'white']];
            }
            foreach ($research as $html) {
                $theme = str_contains(strtolower($html), 'mean for local government') ? 'uniform-lilac' :
                    (str_contains(strtolower($html), 'mean for the public') ? 'putty' : 'white');
                array_push($rows, ...$this->textRows($html, $theme));
            }
        }

        if ($item['type'] === 'staff') {
            $institution = $this->plain($meta['university_name'] ?? '');
            $role = $this->plain($meta['position'] ?? '');
            if ($role || $institution) {
                $rows[] = $this->wysiwyg('<p><strong>' . esc_html($role) . '</strong>' . ($institution ? '<br>' . esc_html($institution) : '') . '</p>');
            }
        }
        array_push($rows, ...$this->textRows($body));
        if (!empty($meta['fusion'])) {
            $teamRows = $this->textRows(wpautop($meta['fusion']));
            foreach ($teamRows as &$teamRow) {
                if (preg_match('/<h[23][^>]*>[^<]*Research Team/i', $teamRow['contentHtml'] ?? '')) {
                    $teamRow['options']['theme'] = 'sunshine-yellow';
                }
            }
            unset($teamRow);
            array_push($rows, ...$teamRows);
        }

        if (!empty($meta['quote_quote'])) {
            $rows[] = $this->wysiwyg('<blockquote>' . $this->cleanHtml(wpautop($meta['quote_quote'])) . '</blockquote>', 'uniform-lilac');
        }

        $layouts = $this->decode($meta['flexible_home'] ?? '');
        if (is_array($layouts)) {
            foreach ($layouts as $index => $layout) {
                if ($layout !== 'staff_manager') {
                    continue;
                }
                $people = $this->decode($meta['flexible_home_' . $index . '_posts'] ?? '');
                $members = [];
                foreach (is_array($people) ? $people : [] as $personId) {
                    $person = $this->items[$personId] ?? null;
                    if (!$person || !isset($this->postMap[$personId])) {
                        continue;
                    }
                    $members[] = ['image' => $this->media((int) ($person['meta']['_thumbnail_id'] ?? 0)),
                        'title' => $this->plain($person['title']),
                        'position' => $this->plain($person['meta']['university_name'] ?? ''),
                        'link' => ['title' => $this->plain($person['title']), 'url' => get_permalink($this->postMap[$personId]), 'target' => '']];
                }
                if ($members) {
                    $rows[] = ['acf_fc_layout' => 'meetTheTeam', 'teamMembers' => $members,
                        'preContentHtml' => '<h2>' . esc_html($this->plain($meta['flexible_home_' . $index . '_title'] ?? '')) . '</h2>',
                        'backgroundColor' => '#ffe626', 'options' => ['displayStyle' => 'phirstTeam']];
                }
            }
        }

        if (!empty($meta['phirst_teams_teams'])) {
            $cards = [];
            for ($index = 0; $index < (int) $meta['phirst_teams_teams']; $index++) {
                $link = $this->decode($meta['phirst_teams_teams_' . $index . '_link'] ?? '');
                if (is_array($link)) {
                    $cards[] = ['image' => $this->media((int) ($meta['phirst_teams_teams_' . $index . '_image'] ?? 0)),
                        'contentHtml' => '<h2>' . esc_html($this->plain($link['title'])) . '</h2>', 'link' => $this->sourceLink($link)];
                }
            }
            if ($cards) {
                $heading = '<h2>' . esc_html($meta['phirst_teams_title'] ?? 'PHIRST Teams') . '</h2>';
                if (!empty($meta['phirst_teams_text'])) {
                    $rows[] = $this->wysiwyg($heading . $this->cleanHtml(wpautop($meta['phirst_teams_text'])));
                    $heading = '';
                }
                $rows[] = $this->grid('manual', $cards, $heading);
            }
        }

        if (!empty($meta['contact_post_email']) || !empty($meta['contact_post_subtitle'])) {
            $contact = '<h2>' . esc_html(($meta['contact_post_title'] ?? '') ?: 'Key contact') . '</h2><p>' .
                esc_html($meta['contact_post_subtitle'] ?? '');
            if (!empty($meta['contact_post_phone'])) {
                $contact .= '<br>' . esc_html($meta['contact_post_phone']);
            }
            if (!empty($meta['contact_post_email'])) {
                $contact .= '<br><a href="mailto:' . esc_attr($meta['contact_post_email']) . '">' . esc_html($meta['contact_post_email']) . '</a>';
            }
            $rows[] = $this->wysiwyg($contact . '</p>');
        }
        $logos = [];
        for ($index = 0; $index < (int) ($meta['partners_partners'] ?? 0); $index++) {
            $image = $this->media((int) ($meta['partners_partners_' . $index . '_logo'] ?? 0));
            if ($image) {
                $logos[] = ['image' => $image];
            }
        }
        if ($logos) {
            $rows[] = ['acf_fc_layout' => 'listLogos', 'items' => $logos, 'options' => ['displayStyle' => 'default', 'theme' => 'white']];
        }
        return $rows;
    }

    private function findLocal(array $item): int
    {
        $existing = get_posts(['post_type' => ['page', 'post', 'person'], 'post_status' => 'any', 'posts_per_page' => 1,
            'meta_key' => '_phirst_source_id', 'meta_value' => $item['id'], 'fields' => 'ids']);
        if ($existing) {
            return (int) $existing[0];
        }
        if ($item['type'] === 'post') {
            $existing = get_posts(['post_type' => 'post', 'post_status' => 'any', 'posts_per_page' => 1,
                'meta_key' => '_phirst_source_post_id', 'meta_value' => $item['id'], 'fields' => 'ids']);
            if ($existing) {
                return (int) $existing[0];
            }
        }
        $aliases = [3139 => 'contact', 5221 => 'health-care-professionals', 5251 => 'about-us', 6780 => 'privacy-policy'];
        $page = get_page_by_path($aliases[$item['id']] ?? $item['slug'], OBJECT, $item['type'] === 'staff' ? 'person' : $item['type']);
        return $page ? (int) $page->ID : 0;
    }

    private function saveTerms(array $item, int $id): void
    {
        $groups = [];
        foreach ($item['terms'] as $term) {
            $taxonomy = match ($term['taxonomy']) {
                'theme' => 'evaluation_theme', 'profile_types' => 'person_group',
                'post_tag' => 'post_tag', 'category' => 'category', default => '',
            };
            if ($taxonomy) {
                $groups[$taxonomy][] = $this->term($term, $taxonomy);
            }
        }
        if ($item['type'] === 'post') {
            $groups['category'][] = $this->term(['slug' => 'phirst-news', 'name' => 'PHIRST News'], 'category');
        }
        foreach ($groups as $taxonomy => $ids) {
            wp_set_object_terms($id, array_values(array_unique($ids)), $taxonomy);
        }
    }

    private function saveSeo(array $item, int $id): void
    {
        $publicSeo = $this->api[$item['id']]['yoast_head_json'] ?? [];
        $title = $publicSeo['title'] ?? $item['meta']['_yoast_wpseo_title'] ?? '';
        if (!$title || str_contains($title, '%%')) {
            $title = $this->plain($item['title']) . ' – NIHR PHIRST';
        }
        $description = $publicSeo['description'] ?? $item['meta']['_yoast_wpseo_metadesc'] ?? '';
        update_field('phirstSeoTitle', $this->plain($title), $id);
        update_field('phirstSeoDescription', $this->plain($description), $id);
        update_post_meta($id, '_yoast_wpseo_title', $this->plain($title));
        update_post_meta($id, '_yoast_wpseo_metadesc', $this->plain($description));
        if (($item['meta']['_yoast_wpseo_meta-robots-noindex'] ?? '') === '1') {
            update_post_meta($id, '_phirst_source_noindex', 1);
        }
    }

    public function run(): array
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        register_taxonomy_for_object_type('post_tag', 'person');
        $this->headerImage = $this->designImage('evaluation-header.png', 'Community health and vaccination services');
        $this->postMap[5165] = $this->home;
        $oldLocalUrls = [];

        // Create identities first. Parent IDs and internal links resolve only after
        // every destination exists; source credentials and users are never copied.
        foreach ($this->items as $sourceId => $item) {
            if ($sourceId === 5165 || $sourceId === 5713) {
                continue;
            }
            $id = $this->findLocal($item);
            if ($id) {
                $oldLocalUrls[get_permalink($id)] = $id;
            }
            $post = [
                'post_type' => $item['type'] === 'staff' ? 'person' : $item['type'],
                'post_status' => 'publish', 'post_title' => $this->plain($item['title']), 'post_name' => $item['slug'],
                'post_date' => $item['date'], 'post_date_gmt' => $item['date_gmt'],
                'post_content' => $item['content'], 'post_excerpt' => $this->plain($item['excerpt']),
                'comment_status' => 'closed', 'ping_status' => 'closed',
            ];
            if ($item['type'] === 'post') {
                foreach ($this->items as $page) {
                    if ($page['type'] === 'page' && $page['link'] === $item['link']) {
                        $post['post_name'] .= '-news';
                        break;
                    }
                }
            }
            if ($id) {
                $post['ID'] = $id;
            }
            $id = wp_insert_post(wp_slash($post), true);
            if (is_wp_error($id)) {
                WP_CLI::error($id->get_error_message());
            }
            $this->postMap[$sourceId] = (int) $id;
            update_post_meta($id, '_phirst_source_id', $sourceId);
            update_post_meta($id, '_phirst_legacy_fields', wp_slash($item['meta']));
            update_post_meta($id, '_phirst_legacy_author', $item['author']);
            update_post_meta($id, '_phirst_legacy_modified', $item['modified']);
            update_field('phirstSourceUrl', $item['link'], $id);
        }
        foreach ($this->items as $sourceId => $item) {
            if (!isset($this->postMap[$sourceId]) || $sourceId === 5165) {
                continue;
            }
            $id = $this->postMap[$sourceId];
            if ($item['type'] === 'page') {
                wp_update_post(['ID' => $id, 'post_parent' => $this->postMap[$item['parent']] ?? 0]);
            }
            $path = rawurldecode(wp_parse_url($item['link'], PHP_URL_PATH) ?: '/');
            if (!isset($this->urlMap[$path]) || $item['type'] === 'page') {
                $this->urlMap[$path] = get_permalink($id);
            }
            $this->saveTerms($item, $id);
        }
        $this->urlMap['/'] = home_url('/');
        $this->urlMap['/staff/'] = home_url('/staff/');
        // This obsolete source link is 404 on the legacy seminar page.
        $this->urlMap['/evaluations/an-evaluation-of-the-change-from-face-to-face-to-telephone-specialist-smoking-cessation-service-and-its-effects-on-underserved-communities-in-bedford-central-bedfordshire-and-milton-keynes/'] = get_permalink($this->postMap[5764]);

        foreach ($this->items as $sourceId => $item) {
            if (!isset($this->postMap[$sourceId]) || $sourceId === 5165) {
                continue;
            }
            $id = $this->postMap[$sourceId];
            $isEvaluation = ($item['meta']['_wp_page_template'] ?? '') === 'templates/evaluating.php';
            $kind = $isEvaluation ? 'evaluation' : 'standard';
            update_field('phirstPageKind', $kind, $id);
            $thumbnail = $this->media((int) ($item['meta']['_thumbnail_id'] ?? 0));
            $currentThumbnail = get_post_thumbnail_id($id);
            $approvedArtwork = $sourceId === 12420 && get_post_meta($currentThumbnail, '_phirst_design_asset', true);
            if ($thumbnail && !$approvedArtwork) {
                set_post_thumbnail($id, $thumbnail);
            }
            if (!$currentThumbnail && !$thumbnail && $item['type'] !== 'staff') {
                set_post_thumbnail($id, $this->headerImage);
            }
            $hash = hash('sha256', json_encode([$item['content'], $item['meta'], $item['terms']]));
            if ($this->replaceComponents || get_post_meta($id, '_phirst_component_source_hash', true) !== $hash) {
                update_field('pageComponents', $this->buildRows($item), $id);
                delete_post_meta($id, '_phirst_finalized');
                update_post_meta($id, '_phirst_component_source_hash', $hash);
            }
            $this->saveSeo($item, $id);
            update_field('show_block', $item['slug'] !== 'newsletter-sign-up' ? 1 : 0, $id);
            if ($item['type'] === 'post') {
                update_field('externalStoryLink', '', $id);
                $this->report['posts']++;
            } elseif ($item['type'] === 'staff') {
                update_field('personRole', $this->plain($item['meta']['position'] ?? ''), $id);
                update_field('personInstitution', $this->plain($item['meta']['university_name'] ?? ''), $id);
                update_field('legacyUrl', $item['link'], $id);
                $this->report['people']++;
            } else {
                $this->report['pages']++;
                $this->report['evaluations'] += (int) $isEvaluation;
            }
            if (($this->report['pages'] + $this->report['posts'] + $this->report['people']) % 20 === 0) {
                WP_CLI::log('Imported content: ' . ($this->report['pages'] + $this->report['posts'] + $this->report['people']));
            }
        }

        $this->createThemePages();
        $this->createListings();
        $this->configureNewsletter();
        $this->preserveLegacyCatalogue();
        $redirects = get_option('phirst_legacy_redirects', []);
        foreach ($oldLocalUrls as $url => $id) {
            $resolved = url_to_postid($url);
            if ($url !== get_permalink($id) && (!$resolved || $resolved === $id)) {
                $redirects[wp_parse_url($url, PHP_URL_PATH)] = $id;
            }
        }
        update_option('phirst_legacy_redirects', $redirects, false);
        flush_rewrite_rules(false);
        $this->report['sourcePages'] = count($this->cache['pages']);
        $this->report['sourcePosts'] = count($this->cache['posts']);
        $this->report['sourceToLocal'] = $this->postMap;
        $this->report['homePreserved'] = $this->homeBefore === (get_field('pageComponents', $this->home, false) ?: []);
        file_put_contents($this->directory . '/migration-report.json', wp_json_encode($this->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $this->report;
    }

    private function createThemePages(): void
    {
        $parent = $this->postMap[5171];
        foreach (get_terms(['taxonomy' => 'evaluation_theme', 'hide_empty' => true]) as $term) {
            $id = (int) get_term_meta($term->term_id, '_phirst_landing_page', true);
            $post = ['post_type' => 'page', 'post_status' => 'publish', 'post_parent' => $parent,
                'post_title' => $term->name, 'post_name' => $term->slug];
            if ($id) {
                $post['ID'] = $id;
            }
            $id = wp_insert_post(wp_slash($post));
            update_term_meta($term->term_id, '_phirst_landing_page', $id);
            update_field('phirstPageKind', 'theme', $id);
            update_field('evaluationThemeTerm', $term->term_id, $id);
            $examples = get_posts(['post_type' => 'page', 'posts_per_page' => 1, 'meta_key' => 'phirstPageKind', 'meta_value' => 'evaluation',
                'tax_query' => [['taxonomy' => 'evaluation_theme', 'field' => 'term_id', 'terms' => [$term->term_id]]]]);
            $image = $examples ? get_post_thumbnail_id($examples[0]) : $this->headerImage;
            set_post_thumbnail($id, $image ?: $this->headerImage);
            if ($this->replaceComponents || !get_field('pageComponents', $id, false)) {
                update_field('pageComponents', [$this->hero('Evaluations'),
                    $this->wysiwyg('<h2>' . esc_html($term->name) . '</h2><p>Browse PHIRST evaluations in this theme.</p>'),
                    $this->grid('evaluationsInTheme')], $id);
            }
            update_field('show_block', 1, $id);
            update_field('phirstSeoTitle', $term->name . ' evaluations – NIHR PHIRST', $id);
            $this->report['themes']++;
        }
    }

    private function createListings(): void
    {
        $id = $this->postMap[5171];
        $introduction = '<h2>PHIRST Evaluations</h2><p>Browse the full range of Local Government initiatives selected by NIHR for research input to date.</p>' .
            '<p>Each evaluation is allocated to one of our <a href="' . esc_url(get_permalink($this->postMap[5251])) . '">ten PHIRSTs</a> which are ready to work with local authorities to co-produce research to underpin priority initiatives.</p>';
        update_field('pageComponents', [$this->hero('Evaluations'), $this->wysiwyg($introduction), $this->grid('evaluationThemes')], $id);
        update_field('phirstPageKind', 'archive', $id);
        $staff = get_page_by_path('staff');
        $staffId = $staff ? $staff->ID : wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Our people', 'post_name' => 'staff']);
        if (!get_field('pageComponents', $staffId, false)) {
            update_field('pageComponents', [$this->hero('Our people'), ['acf_fc_layout' => 'gridPeople', 'options' => ['theme' => 'white']]], $staffId);
            update_field('show_block', 1, $staffId);
        }
        $news = get_page_by_path('news-events');
        if ($news) {
            update_field('pageComponents', [$this->hero('News & Events'), $this->wysiwyg('<h2>News & Events</h2>'), $this->grid('news')], $news->ID);
            update_field('show_block', 1, $news->ID);
        }
    }

    private function configureNewsletter(): void
    {
        $existing = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1,
            'meta_key' => '_phirst_design_asset', 'meta_value' => 'newsletter.png', 'fields' => 'ids']);
        update_field('global_BlockMailingList_globalImage', $existing[0] ?? 0, 'option');
        update_field('global_BlockMailingList_globalImagePosition', 'right', 'option');
        $signup = $this->postMap[6158];
        update_field('global_BlockMailingList_globalContentHtml', '<h2>Sign up to our newsletter</h2><p>Sign up to the PHIRST mailing list to receive regular updates.</p><p><a class="button" href="' . esc_url(get_permalink($signup)) . '">Click here to sign up</a></p>', 'option');
        update_field('global_BlockMailingList_globalMailingOptions', ['theme' => 'uniform-lilac', 'backgroundColor' => '#c4c5ff'], 'option');
    }

    private function preserveLegacyCatalogue(): void
    {
        $existing = get_posts(['post_type' => 'page', 'post_status' => 'draft', 'name' => 'legacy-evaluations-reference', 'posts_per_page' => 1, 'fields' => 'ids']);
        if ($existing) {
            return;
        }
        $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'legacy-evaluations-reference',
            'post_title' => 'Legacy Evaluations — map and filters reference',
            'post_content' => '<p>The old catalogue, map markup, embedded data and theme definitions were preserved in the private migration source snapshot on 23 October 2026. The active catalogue uses theme image tiles.</p><p>Source: <a href="https://phirst.nihr.ac.uk/evaluations/">Original Evaluations catalogue</a></p>']);
        update_post_meta($id, '_phirst_legacy_catalogue_html', wp_slash(file_get_contents($this->directory . '/html/pages-5171.html')));
        update_post_meta($id, '_phirst_legacy_fields', wp_slash($this->items[5171]['meta']));
    }
}
